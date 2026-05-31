<?php
/**
 * Payments import adapter.
 * Columns (XLSX): Time, Amount, Mode, Source, Transaction ID,
 *                 Invoice ID, Invoice Type, Parent, Phone Number
 * Requires context: branch_id
 * Duplicate detection: invoice_id + amount + paid_at (within 1 minute)
 */
class OPB_Payments_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'payments'; }

    public function column_groups(): array {
        return [
            '*Time'          => ['Time','time','Date','paid_at'],
            '*Amount'        => ['Amount','amount'],
            'Mode'           => ['Mode','mode','Payment Mode'],
            'Source'         => ['Source','source'],
            'Transaction ID' => ['Transaction ID','transaction_id','Txn ID'],
            '*Invoice ID'    => ['Invoice ID','invoice_id','Invoice No'],
            'Invoice Type'   => ['Invoice Type','invoice_type'],
            'Phone'          => ['Phone Number','phone number','Phone','phone'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch','detail'=>"branch_id required in context"];
        }

        $amount_raw = $this->col($row, ['Amount','amount']);
        if ( $amount_raw === '' ) {
            return ['status'=>'skipped','reason_code'=>'missing_amount','detail'=>"Row $row_num: Amount is empty"];
        }

        $time_raw = $this->col($row, ['Time','time','Date','paid_at']);
        $paid_at  = $this->parse_datetime($time_raw);
        if ( ! $paid_at ) {
            return ['status'=>'skipped','reason_code'=>'invalid_datetime',
                'detail'=>"Cannot parse payment time '$time_raw'"];
        }

        $legacy_inv_no = $this->col($row, ['Invoice ID','invoice_id','Invoice No']);
        if ( ! $legacy_inv_no ) {
            return ['status'=>'skipped','reason_code'=>'missing_invoice_id',
                'detail'=>"Row $row_num: no Invoice ID"];
        }

        // Resolve invoice
        $invoice_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_invoices
              WHERE legacy_invoice_number=%s AND branch_id=%d LIMIT 1",
            $legacy_inv_no, $branch_id
        ));
        if ( ! $invoice_id ) {
            return ['status'=>'skipped','reason_code'=>'invoice_not_found',
                'detail'=>"No imported invoice for legacy Invoice ID $legacy_inv_no — import invoices first"];
        }

        // Duplicate: same invoice, same amount, within 60 seconds of paid_at
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_payments
              WHERE invoice_id=%d AND amount=%f
                AND ABS(TIMESTAMPDIFF(SECOND, paid_at, %s)) < 60
             LIMIT 1",
            $invoice_id, (float)$amount_raw, $paid_at
        ));
        if ( $exists ) {
            return ['status'=>'skipped','reason_code'=>'duplicate',
                'detail'=>"Payment already exists (id=$exists)"];
        }

        if ( ! $dry ) {
            $mode_raw = $this->col($row, ['Mode','mode','Payment Mode'], 'Cash');
            $src_raw  = strtolower($this->col($row, ['Source','source'], 'Manual'));
            $source   = in_array($src_raw, ['manual','online']) ? ucfirst($src_raw) : 'Manual';

            $wpdb->insert("{$wpdb->prefix}opb_payments", [
                'invoice_id'     => $invoice_id,
                'branch_id'      => $branch_id,
                'paid_at'        => $paid_at,
                'amount'         => (float)$amount_raw,
                'mode'           => $this->normalise_mode($mode_raw),
                'source'         => $source,
                'transaction_id' => sanitize_text_field(
                    $this->col($row,['Transaction ID','transaction_id','Txn ID'])) ?: null,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
