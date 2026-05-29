<?php
/**
 * Generates or recalculates invoices from booking data.
 */
class OPB_Invoice_Generator {

    /**
     * Create an invoice for a booking (called on booking creation).
     */
    public static function create_for_booking( int $booking_id ): int|false {
        global $wpdb;

        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_bookings WHERE id = %d", $booking_id
        ), ARRAY_A );
        if ( ! $booking ) return false;

        // Check if invoice already exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_invoices WHERE booking_id = %d", $booking_id
        ) );
        if ( $existing ) return (int) $existing;

        $totals = self::calculate_booking_totals( $booking_id );

        $wpdb->insert( "{$wpdb->prefix}opb_invoices", [
            'booking_id'       => $booking_id,
            'branch_id'        => $booking['branch_id'],
            'invoice_type'     => 'Booking',
            'invoice_date'     => $booking['booking_date'],
            'revenue'          => $totals['revenue'],
            'base_amount'      => $totals['base_amount'],
            'addon_amount'     => $totals['addon_amount'],
            'discount_amount'  => $totals['discount_amount'],
            'additional_amount'=> 0,
            'additional_discount_amount' => 0,
            'paid'             => 0,
            'due'              => $totals['revenue'],
            'payment_status'   => 'Unpaid',
        ] );

        $invoice_id = (int) $wpdb->insert_id;
        if ( ! $invoice_id ) return false;

        // Insert line items
        self::insert_line_items( $invoice_id, $totals['line_items'] );

        // Update booking total
        $wpdb->update( "{$wpdb->prefix}opb_bookings",
            [ 'total_billing_amount' => $totals['revenue'] ],
            [ 'id' => $booking_id ]
        );

        return $invoice_id;
    }

    /**
     * Recalculate and update an existing invoice.
     */
    public static function recalculate( int $invoice_id ): void {
        global $wpdb;

        $invoice = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_invoices WHERE id = %d", $invoice_id
        ), ARRAY_A );
        if ( ! $invoice ) return;

        $totals = self::calculate_booking_totals( (int) $invoice['booking_id'] );

        // Keep paid amount, recalculate due
        $paid = (float) $invoice['paid'];
        $due  = round( $totals['revenue'] - $paid, 2 );

        $wpdb->update( "{$wpdb->prefix}opb_invoices", [
            'revenue'         => $totals['revenue'],
            'base_amount'     => $totals['base_amount'],
            'addon_amount'    => $totals['addon_amount'],
            'discount_amount' => $totals['discount_amount'],
            'due'             => $due,
            'payment_status'  => self::resolve_payment_status( $totals['revenue'], $paid ),
        ], [ 'id' => $invoice_id ] );

        // Replace auto-generated line items (keep manual ones)
        $wpdb->delete( "{$wpdb->prefix}opb_invoice_line_items", [
            'invoice_id' => $invoice_id,
            'is_return'  => 0,
        ] );
        $wpdb->delete( "{$wpdb->prefix}opb_invoice_line_items", [
            'invoice_id'  => $invoice_id,
            'bill_section' => 'Discount',
        ] );

        self::insert_line_items( $invoice_id, $totals['line_items'] );

        // Update booking total
        $wpdb->update( "{$wpdb->prefix}opb_bookings",
            [ 'total_billing_amount' => $totals['revenue'] ],
            [ 'id' => $invoice['booking_id'] ]
        );
    }

    private static function calculate_booking_totals( int $booking_id ): array {
        global $wpdb;

        $stays = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.*, p.breed_size, p.name as pet_name
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_pets p ON p.id = bs.pet_id
             WHERE bs.booking_id = %d",
            $booking_id
        ), ARRAY_A );

        $addons = $wpdb->get_results( $wpdb->prepare(
            "SELECT ba.*, a.name, a.service_type, a.base_amount as unit_price
             FROM {$wpdb->prefix}opb_booking_addons ba
             JOIN {$wpdb->prefix}opb_addon_services a ON a.id = ba.addon_id
             WHERE ba.booking_id = %d",
            $booking_id
        ), ARRAY_A );

        $line_items    = [];
        $base_amount   = 0.0;
        $discount_amount = 0.0;
        $addon_amount  = 0.0;

        foreach ( $stays as $stay ) {
            if ( ! $stay['boarding_service_id'] ) continue;

            $result = OPB_Pricing_Engine::calculate(
                $stay,
                (int) $stay['boarding_service_id'],
                $stay['check_in_date'],
                $stay['check_out_date'],
                $stay['meal_type'] ?? 'PARENT_SUPPLIED_MEAL',
                ''
            );

            foreach ( $result['line_items'] as $li ) {
                $li['stay_pet'] = $stay['pet_name'];
                $line_items[]   = $li;
                if ( $li['bill_section'] === 'Discount' ) {
                    $discount_amount += abs( (float) $li['total'] );
                } else {
                    $base_amount += (float) $li['total'];
                }
            }

            // Late checkout fees
            if ( ! empty( $stay['late_checkout_fees'] ) && (float) $stay['late_checkout_fees'] > 0 ) {
                $lf = (float) $stay['late_checkout_fees'];
                $line_items[] = [
                    'bill_section'   => 'Additional',
                    'bill_item_name' => 'Late checkout fee',
                    'quantity'       => 1,
                    'amount'         => $lf,
                    'subtotal'       => $lf,
                    'total'          => $lf,
                    'is_return'      => 0,
                ];
                $base_amount += $lf;
            }
        }

        foreach ( $addons as $addon ) {
            $addon_total = isset( $addon['final_amount'] ) && $addon['final_amount'] !== null
                ? (float) $addon['final_amount']
                : (float) $addon['unit_price'] * (int) $addon['count'];

            $line_items[] = [
                'bill_section'   => 'Add-on',
                'bill_item_name' => $addon['name'] . ( $addon['count'] > 1 ? " × {$addon['count']}" : '' ),
                'quantity'       => $addon['count'],
                'amount'         => (float) $addon['unit_price'],
                'subtotal'       => $addon_total,
                'total'          => $addon_total,
                'is_return'      => 0,
            ];
            $addon_amount += $addon_total;
        }

        $revenue = round( $base_amount + $addon_amount - $discount_amount, 2 );

        return compact( 'line_items', 'base_amount', 'addon_amount', 'discount_amount', 'revenue' );
    }

    private static function insert_line_items( int $invoice_id, array $line_items ): void {
        global $wpdb;
        foreach ( $line_items as $li ) {
            $wpdb->insert( "{$wpdb->prefix}opb_invoice_line_items", [
                'invoice_id'     => $invoice_id,
                'bill_section'   => $li['bill_section']   ?? 'Base',
                'bill_item_name' => $li['bill_item_name'] ?? '',
                'quantity'       => $li['quantity']        ?? 1,
                'amount'         => $li['amount']          ?? 0,
                'subtotal'       => $li['subtotal']        ?? 0,
                'total'          => $li['total']           ?? 0,
                'is_return'      => $li['is_return']       ?? 0,
            ] );
        }
    }

    public static function resolve_payment_status( float $revenue, float $paid ): string {
        if ( $revenue <= 0 )   return 'No bill';
        if ( $paid <= 0 )      return 'Unpaid';
        if ( $paid >= $revenue ) return $paid > $revenue ? 'Overpaid' : 'Paid';
        return 'Partially paid';
    }

    /**
     * Update paid/due after a payment is recorded.
     */
    public static function sync_payment_totals( int $invoice_id ): void {
        global $wpdb;

        $paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}opb_payments WHERE invoice_id = %d",
            $invoice_id
        ) );

        $revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT revenue FROM {$wpdb->prefix}opb_invoices WHERE id = %d",
            $invoice_id
        ) );

        $due    = round( $revenue - $paid, 2 );
        $status = self::resolve_payment_status( $revenue, $paid );

        $wpdb->update( "{$wpdb->prefix}opb_invoices",
            [ 'paid' => $paid, 'due' => $due, 'payment_status' => $status ],
            [ 'id' => $invoice_id ]
        );

        // Sync booking payment_status
        $booking_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT booking_id FROM {$wpdb->prefix}opb_invoices WHERE id = %d", $invoice_id
        ) );
        if ( $booking_id ) {
            $wpdb->update( "{$wpdb->prefix}opb_bookings",
                [ 'payment_status' => $status ],
                [ 'id' => $booking_id ]
            );
        }
    }
}
