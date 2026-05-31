<?php
/**
 * Invoices import adapter.
 * Columns (XLSX): Invoice No, Invoice Type, Invoice Date, Booking ID, Booking Date,
 *                 Pet, Parent, Phone Number, Revenue, Base Amount, Add-On Amount,
 *                 Discount Amount, Additional Amount, Additional Discount Amount,
 *                 Paid, Due, Payment Mode
 * Requires context: branch_id
 * Duplicate detection: legacy_invoice_number + branch_id
 */
class OPB_Invoices_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'invoices'; }

    public function column_groups(): array {
        return [
            '*Invoice No'    => ['Invoice No','invoice_no','Invoice Number','invoice_number','ID'],
            '*Invoice Date'  => ['Invoice Date','invoice_date','Date'],
            '*Booking ID'    => ['Booking ID','booking_id'],
            'Revenue'        => ['Revenue','revenue','Total','total_amount'],
            'Base Amount'    => ['Base Amount','base_amount'],
            'Add-On Amount'  => ['Add-On Amount','addon_amount'],
            'Discount Amount'=> ['Discount Amount','discount_amount'],
            'Additional Amt' => ['Additional Amount','additional_amount'],
            'Add. Discount'  => ['Additional Discount Amount','additional_discount_amount'],
            'Paid'           => ['Paid','paid_amount','paid'],
            'Due'            => ['Due','due_amount','due'],
            'Payment Mode'   => ['Payment Mode','payment_mode','Mode'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch','detail'=>"branch_id required in context"];
        }

        $inv_no = $this->col($row, ['Invoice No','invoice_no','Invoice Number','invoice_number','ID']);
        if ( ! $inv_no ) {
            return ['status'=>'skipped','reason_code'=>'missing_invoice_no','detail'=>"Row $row_num: no invoice number"];
        }

        $inv_date_raw = $this->col($row, ['Invoice Date','invoice_date','Date']);
        $inv_date     = $this->parse_date($inv_date_raw);
        if ( ! $inv_date ) {
            return ['status'=>'skipped','reason_code'=>'invalid_date',
                'detail'=>"Cannot parse invoice date '$inv_date_raw'"];
        }

        // Duplicate check
        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_invoices
              WHERE legacy_invoice_number=%s AND branch_id=%d LIMIT 1",
            $inv_no, $branch_id
        ));
        if ( $exists ) {
            return ['status'=>'skipped','reason_code'=>'duplicate',
                'detail'=>"Invoice $inv_no already exists (id=$exists)"];
        }

        // Resolve booking_id from legacy booking ID
        $legacy_booking_id = $this->col($row, ['Booking ID','booking_id']);
        $booking_id = 0;
        if ( $legacy_booking_id !== '' ) {
            $booking_id = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_bookings
                  WHERE legacy_id=%d AND branch_id=%d LIMIT 1",
                (int)$legacy_booking_id, $branch_id
            ));
        }
        if ( ! $booking_id ) {
            return ['status'=>'skipped','reason_code'=>'booking_not_found',
                'detail'=>"No imported booking found for legacy Booking ID $legacy_booking_id — import bookings first"];
        }

        if ( ! $dry ) {
            $revenue    = (float)$this->col($row, ['Revenue','revenue','Total','total_amount'], '0');
            $base       = (float)$this->col($row, ['Base Amount','base_amount'], '0');
            $addon      = (float)$this->col($row, ['Add-On Amount','addon_amount'], '0');
            $discount   = (float)$this->col($row, ['Discount Amount','discount_amount'], '0');
            $additional = (float)$this->col($row, ['Additional Amount','additional_amount'], '0');
            $add_disc   = (float)$this->col($row, ['Additional Discount Amount','additional_discount_amount'], '0');
            $paid       = (float)$this->col($row, ['Paid','paid_amount','paid'], '0');
            $due        = (float)$this->col($row, ['Due','due_amount','due'], '0');
            $mode_raw   = $this->col($row, ['Payment Mode','payment_mode','Mode']);

            $pay_status = 'Unpaid';
            if ( $due <= 0 && $paid > 0 ) $pay_status = 'Paid';
            elseif ( $paid > $revenue )   $pay_status = 'Overpaid';
            elseif ( $paid > 0 )          $pay_status = 'Partially paid';
            elseif ( $revenue == 0 )      $pay_status = 'No bill';

            $inv_type_raw = $this->col($row, ['Invoice Type','invoice_type'], 'Booking');
            $inv_type     = in_array($inv_type_raw, ['Booking','Manual']) ? $inv_type_raw : 'Booking';

            $wpdb->insert("{$wpdb->prefix}opb_invoices", [
                'booking_id'                => $booking_id,
                'branch_id'                 => $branch_id,
                'legacy_invoice_number'     => sanitize_text_field($inv_no),
                'invoice_type'              => $inv_type,
                'invoice_date'              => $inv_date,
                'revenue'                   => $revenue,
                'base_amount'               => $base,
                'addon_amount'              => $addon,
                'discount_amount'           => $discount,
                'additional_amount'         => $additional,
                'additional_discount_amount'=> $add_disc,
                'paid'                      => $paid,
                'due'                       => $due,
                'payment_status'            => $pay_status,
                'payment_mode'              => $mode_raw ? sanitize_text_field($mode_raw) : null,
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
