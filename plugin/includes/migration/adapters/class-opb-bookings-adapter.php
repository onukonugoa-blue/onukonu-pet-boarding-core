<?php
/**
 * Bookings import adapter.
 * Columns (XLSX): Booking ID, Booking Date, Pet Parent, Phone, Email,
 *                 Invoice Number, Payment Status, Total Billing Amount,
 *                 Service Types, Booking Source, Notes, Additional Instruction, Created At
 * Requires context: branch_id
 * Duplicate detection: legacy_id + branch_id
 */
class OPB_Bookings_Adapter extends OPB_Import_Adapter {

    public function entity(): string { return 'bookings'; }

    public function column_groups(): array {
        return [
            'legacy_id'      => ['Booking ID','booking_id','ID','id','Legacy ID'],
            '*Booking Date'  => ['Booking Date','booking_date','Date'],
            '*Phone'         => ['Phone','phone','Phone Number','phone number','Mobile'],
            'Payment Status' => ['Payment Status','payment_status'],
            'Total Amount'   => ['Total Billing Amount','Total','total','Amount','total_billing_amount'],
            'Service Types'  => ['Service Types','service_types'],
            'Booking Source' => ['Booking Source','booking_source','Source'],
            'Notes'          => ['Notes','notes'],
            'Add. Instruction'=> ['Additional Instruction','additional_instruction'],
        ];
    }

    protected function process_row( array $row, int $row_num, bool $dry, array $ctx ): array {
        global $wpdb;

        $branch_id = (int)($ctx['branch_id'] ?? 0);
        if ( ! $branch_id ) {
            return ['status'=>'skipped','reason_code'=>'missing_branch','detail'=>"branch_id required in context"];
        }

        $phone = $this->col($row, ['Phone','phone','Phone Number','phone number','Mobile']);
        if ( ! $phone ) {
            return ['status'=>'skipped','reason_code'=>'missing_phone','detail'=>"Row $row_num: no phone column"];
        }

        $booking_date_raw = $this->col($row, ['Booking Date','booking_date','Date']);
        $booking_date     = $this->parse_date($booking_date_raw);
        if ( ! $booking_date ) {
            return ['status'=>'skipped','reason_code'=>'invalid_date',
                'detail'=>"Cannot parse booking date '$booking_date_raw'"];
        }

        $client_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_clients WHERE phone=%s", $phone
        ));
        if ( ! $client_id ) {
            return ['status'=>'skipped','reason_code'=>'client_not_found',
                'detail'=>"No client with phone $phone — import clients first"];
        }

        $legacy_id = $this->col($row, ['Booking ID','booking_id','ID','id','Legacy ID']);
        if ( $legacy_id !== '' ) {
            $exists = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}opb_bookings WHERE legacy_id=%d AND branch_id=%d",
                (int)$legacy_id, $branch_id
            ));
            if ( $exists ) {
                return ['status'=>'skipped','reason_code'=>'duplicate',
                    'detail'=>"Booking legacy_id=$legacy_id already exists (id=$exists)"];
            }
        }

        if ( ! $dry ) {
            $raw_status = $this->col($row, ['Payment Status','payment_status'], 'Unpaid');
            $valid_statuses = ['Unpaid','Partially paid','Paid','Overpaid','No bill'];
            $pay_status = in_array($raw_status, $valid_statuses, true) ? $raw_status : 'Unpaid';

            $created_raw = $this->col($row, ['Created At','created_at']);
            $created_at  = $created_raw ? $this->parse_datetime($created_raw) : null;

            $wpdb->insert("{$wpdb->prefix}opb_bookings", [
                'legacy_id'             => $legacy_id !== '' ? (int)$legacy_id : null,
                'branch_id'             => $branch_id,
                'client_id'             => $client_id,
                'booking_date'          => $booking_date,
                'payment_status'        => $pay_status,
                'total_billing_amount'  => (float)$this->col($row,
                    ['Total Billing Amount','Total','total','Amount','total_billing_amount'], '0'),
                'service_types'         => sanitize_text_field(
                    $this->col($row,['Service Types','service_types'])),
                'booking_source'        => sanitize_text_field(
                    $this->col($row,['Booking Source','booking_source','Source'])),
                'notes'                 => sanitize_textarea_field(
                    $this->col($row,['Notes','notes'])),
                'additional_instruction'=> sanitize_textarea_field(
                    $this->col($row,['Additional Instruction','additional_instruction'])),
                'created_at'            => $created_at ?? current_time('mysql'),
            ]);
        }

        return ['status'=>'imported','reason_code'=>'','detail'=>''];
    }
}
