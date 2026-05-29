<?php
/**
 * Pricing Engine — calculates stay amounts from catalogue rows.
 */
class OPB_Pricing_Engine {

    /**
     * Calculate line items for a booking stay.
     *
     * @param array $pet            Pet record from DB
     * @param int   $service_id     Boarding service catalogue ID (any row in the catalogue)
     * @param string $check_in      Y-m-d
     * @param string $check_out     Y-m-d
     * @param string $meal_type     BOARDING_MEALS | PARENT_SUPPLIED_MEAL
     * @param string $kennel_cat    kennel_category string or empty
     * @return array{ line_items: array, total: float }
     */
    public static function calculate(
        array  $pet,
        int    $service_id,
        string $check_in,
        string $check_out,
        string $meal_type     = 'PARENT_SUPPLIED_MEAL',
        string $kennel_cat    = ''
    ): array {
        global $wpdb;

        // Resolve catalogue_name and branch_id from any row in the catalogue
        $ref = $wpdb->get_row( $wpdb->prepare(
            "SELECT catalogue_name, branch_id, boarding_type FROM {$wpdb->prefix}opb_boarding_services WHERE id = %d",
            $service_id
        ) );
        if ( ! $ref ) {
            return [ 'line_items' => [], 'total' => 0.0 ];
        }

        // Load all rows for this catalogue
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_boarding_services
             WHERE branch_id = %d AND catalogue_name = %s AND is_active = 1
             ORDER BY sort_order ASC",
            $ref->branch_id,
            $ref->catalogue_name
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [ 'line_items' => [], 'total' => 0.0 ];
        }

        // Index rows by row_type
        $by_type = [];
        foreach ( $rows as $row ) {
            $by_type[ $row['row_type'] ][] = $row;
        }

        // Parse FLAGS
        $flags = [];
        if ( ! empty( $by_type['FLAGS'] ) ) {
            $flags_raw = $by_type['FLAGS'][0]['extra_info'] ?? '{}';
            $flags = json_decode( $flags_raw, true ) ?: [];
        }

        $boarding_type = $ref->boarding_type; // DAY | OVERNIGHT
        $ci = new \DateTime( $check_in );
        $co = new \DateTime( $check_out );
        $nights = max( 1, (int) $ci->diff( $co )->days );
        if ( $boarding_type === 'DAY' ) {
            $nights = 1;
        }

        $line_items = [];
        $total      = 0.0;

        // 1. Base rate
        $base_row_type = $boarding_type === 'DAY' ? 'DAY_BASE' : 'OVERNIGHT_BASE';
        $base_amount   = 0.0;
        if ( ! empty( $by_type[ $base_row_type ] ) ) {
            $base_amount = (float) $by_type[ $base_row_type ][0]['amount'];
        }
        $base_subtotal = $base_amount * $nights;
        $line_items[]  = [
            'bill_section'  => 'Base',
            'bill_item_name'=> "{$ref->catalogue_name} × {$nights} " . ( $boarding_type === 'DAY' ? 'day' : 'nights' ),
            'quantity'      => $nights,
            'amount'        => $base_amount,
            'subtotal'      => $base_subtotal,
            'total'         => $base_subtotal,
            'is_return'     => 0,
        ];
        $total += $base_subtotal;

        // 2. Breed size modifier
        if ( ! empty( $flags['breedSize'] ) && ! empty( $pet['breed_size'] ) && ! empty( $by_type['BREED_SIZE'] ) ) {
            foreach ( $by_type['BREED_SIZE'] as $bs ) {
                if ( strtolower( $bs['breed_size'] ) === strtolower( $pet['breed_size'] ) ) {
                    $bs_amount = (float) $bs['amount'] * $nights;
                    $line_items[] = [
                        'bill_section'   => 'Base',
                        'bill_item_name' => "Breed size surcharge ({$pet['breed_size']})",
                        'quantity'       => $nights,
                        'amount'         => (float) $bs['amount'],
                        'subtotal'       => $bs_amount,
                        'total'          => $bs_amount,
                        'is_return'      => 0,
                    ];
                    $total += $bs_amount;
                    break;
                }
            }
        }

        // 3. Meal type modifier
        if ( ! empty( $flags['meal'] ) && $meal_type === 'BOARDING_MEALS' && ! empty( $by_type['MEAL'] ) ) {
            foreach ( $by_type['MEAL'] as $meal_row ) {
                if ( strtoupper( $meal_row['meal_type'] ?? '' ) === 'BOARDING_MEALS' ) {
                    $meal_amount  = (float) $meal_row['amount'] * $nights;
                    $line_items[] = [
                        'bill_section'   => 'Base',
                        'bill_item_name' => "Boarding meals × {$nights}",
                        'quantity'       => $nights,
                        'amount'         => (float) $meal_row['amount'],
                        'subtotal'       => $meal_amount,
                        'total'          => $meal_amount,
                        'is_return'      => 0,
                    ];
                    $total += $meal_amount;
                    break;
                }
            }
        }

        // 4. Kennel category surcharge
        if ( ! empty( $flags['kennelCategory'] ) && $kennel_cat && ! empty( $by_type['KENNEL_CATEGORY'] ) ) {
            foreach ( $by_type['KENNEL_CATEGORY'] as $kc ) {
                if ( strtolower( $kc['kennel_category'] ?? '' ) === strtolower( $kennel_cat ) ) {
                    $kc_amount  = (float) $kc['amount'] * $nights;
                    $line_items[] = [
                        'bill_section'   => 'Base',
                        'bill_item_name' => "Kennel category ({$kennel_cat}) × {$nights}",
                        'quantity'       => $nights,
                        'amount'         => (float) $kc['amount'],
                        'subtotal'       => $kc_amount,
                        'total'          => $kc_amount,
                        'is_return'      => 0,
                    ];
                    $total += $kc_amount;
                    break;
                }
            }
        }

        // 5. Longevity discount
        if ( ! empty( $flags['longevity'] ) && ! empty( $by_type['LONGEVITY'] ) ) {
            foreach ( $by_type['LONGEVITY'] as $lon ) {
                $threshold = (int) ( $lon['days'] ?? 0 );
                if ( $threshold > 0 && $nights >= $threshold ) {
                    // amount stored as percentage
                    $pct          = (float) $lon['amount'];
                    $discount_val = round( $base_subtotal * $pct / 100, 2 );
                    $line_items[] = [
                        'bill_section'   => 'Discount',
                        'bill_item_name' => "Long stay discount ({$pct}%)",
                        'quantity'       => 1,
                        'amount'         => -$discount_val,
                        'subtotal'       => -$discount_val,
                        'total'          => -$discount_val,
                        'is_return'      => 1,
                    ];
                    $total -= $discount_val;
                    break; // apply first matching threshold only
                }
            }
        }

        return [
            'line_items' => $line_items,
            'total'      => round( $total, 2 ),
        ];
    }
}
