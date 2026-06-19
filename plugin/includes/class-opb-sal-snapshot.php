<?php
/**
 * OPB_SAL_Snapshot
 *
 * Situational Awareness Layer — Snapshot Engine (v3.1.0)
 *
 * Queries the OPB database and returns a structured operational dataset.
 * This class is the SOLE data source for SAL briefs.
 *
 * PRINCIPLES:
 *   - The database is the only source of truth.
 *   - No computed metrics, no KPIs, no averages.
 *   - Only factual counts, lists, and status flags.
 *   - All queries scoped to today's date in the site timezone.
 *
 * SAFETY GUARANTEE:
 *   All public methods are wrapped in try/catch(\Throwable).
 *   This class will NEVER throw and will NEVER break business workflows.
 */
class OPB_SAL_Snapshot {

    // ── Entry point ────────────────────────────────────────────────────────────

    /**
     * Generate a complete operational snapshot for the given brief type.
     *
     * @param  string $brief_type  'morning' | 'evening' | 'accounts'
     * @return array               Structured operational dataset.
     */
    public static function generate( string $brief_type = 'morning' ): array {
        try {
            $today    = current_time( 'Y-m-d' );
            $now      = current_time( 'mysql' );
            $branches = self::get_branches();

            $snapshot = [
                'generated_at' => $now,
                'brief_type'   => $brief_type,
                'date'         => $today,
                'branches'     => [],
                'totals'       => [],
            ];

            // Per-branch data
            foreach ( $branches as $branch ) {
                $bid  = (int) $branch['id'];
                $data = [ 'branch_id' => $bid, 'branch_name' => $branch['name'] ];

                if ( in_array( $brief_type, [ 'morning', 'evening' ], true ) ) {
                    $data['boarding']   = self::boarding_data( $bid, $today );
                    $data['tasks']      = self::tasks_data( $bid, $today );
                    $data['exceptions'] = self::exception_data( $bid, $today );
                }

                if ( $brief_type === 'morning' ) {
                    $data['clients']    = self::clients_data( $bid, $today );
                }

                if ( $brief_type === 'accounts' ) {
                    $data['invoices']   = self::invoices_data( $bid, $today );
                    $data['payments']   = self::payments_data( $bid, $today );
                    $data['expenses']   = self::expenses_data( $bid, $today );
                }

                $snapshot['branches'][] = $data;
            }

            // Cross-branch totals
            $snapshot['totals'] = self::compute_totals( $snapshot['branches'], $brief_type );

            return $snapshot;

        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] generate() error: ' . $e->getMessage() );
            return [
                'generated_at' => current_time( 'mysql' ),
                'brief_type'   => $brief_type,
                'date'         => current_time( 'Y-m-d' ),
                'error'        => $e->getMessage(),
                'branches'     => [],
                'totals'       => [],
            ];
        }
    }

    // ── Branches ───────────────────────────────────────────────────────────────

    private static function get_branches(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, name, code FROM {$wpdb->prefix}opb_branches WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        ) ?? [];
    }

    // ── Boarding data ──────────────────────────────────────────────────────────

    private static function boarding_data( int $branch_id, string $today ): array {
        global $wpdb;
        $stays_t    = "{$wpdb->prefix}opb_booking_stays";
        $bookings_t = "{$wpdb->prefix}opb_bookings";
        $pets_t     = "{$wpdb->prefix}opb_pets";
        $clients_t  = "{$wpdb->prefix}opb_clients";
        $kennels_t  = "{$wpdb->prefix}opb_kennels";

        // Active stays right now
        $active_stays = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date, bs.kennel,
                    bs.boarding_type, bs.actual_check_in_at, bs.actual_check_out_at,
                    p.name AS pet_name, p.pet_type, p.breed,
                    p.ongoing_medication, p.medication_detail,
                    p.vaccination_status,
                    p.preferences_or_allergies, p.major_illness_history,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.status = 'Active'
             ORDER BY bs.check_out_date ASC",
            $branch_id
        ), ARRAY_A ) ?? [];

        // Arrivals today (check_in_date = today, status = Upcoming or Active)
        $arrivals_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date, bs.boarding_type,
                    bs.check_in_slot, bs.status,
                    p.name AS pet_name, p.pet_type,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_in_date = %s
               AND bs.status IN ('Upcoming','Active')
             ORDER BY bs.check_in_slot ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Departures today (check_out_date = today, status = Active)
        $departures_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date, bs.boarding_type,
                    bs.check_out_slot, bs.status, bs.actual_check_out_at,
                    p.name AS pet_name, p.pet_type,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_out_date = %s
               AND bs.status IN ('Active','Upcoming')
             ORDER BY bs.check_out_slot ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Arrivals tomorrow
        $tomorrow = date( 'Y-m-d', strtotime( $today . ' +1 day' ) );
        $arrivals_tomorrow = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date, bs.boarding_type,
                    bs.check_in_slot,
                    p.name AS pet_name, p.pet_type,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_in_date = %s
               AND bs.status = 'Upcoming'
             ORDER BY bs.check_in_slot ASC",
            $branch_id, $tomorrow
        ), ARRAY_A ) ?? [];

        // Departures tomorrow
        $departures_tomorrow = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date, bs.boarding_type,
                    bs.check_out_slot,
                    p.name AS pet_name, p.pet_type,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_out_date = %s
               AND bs.status = 'Active'
             ORDER BY bs.check_out_slot ASC",
            $branch_id, $tomorrow
        ), ARRAY_A ) ?? [];

        // Overstays: check_out_date < today AND status = Active
        $overstays = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.check_out_date,
                    bs.check_out_slot,
                    p.name AS pet_name, p.pet_type,
                    c.name AS client_name, c.phone AS client_phone
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_out_date < %s
               AND bs.status = 'Active'
             ORDER BY bs.check_out_date ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Kennel capacity
        $kennel_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$kennels_t} WHERE branch_id = %d AND is_active = 1",
            $branch_id
        ) );

        // Completed departures today (for evening brief)
        $completed_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.actual_check_out_at,
                    p.name AS pet_name,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.status = 'Completed'
               AND DATE(bs.actual_check_out_at) = %s",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Completed arrivals today
        $arrived_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.actual_check_in_at,
                    p.name AS pet_name,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.status IN ('Active','Completed')
               AND DATE(bs.actual_check_in_at) = %s",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Animals with ongoing medication
        $on_medication = array_filter( $active_stays, fn( $s ) => (int) ( $s['ongoing_medication'] ?? 0 ) === 1 );
        $on_medication = array_values( $on_medication );

        // Special care animals (has preferences/allergies or illness history)
        $special_care = array_filter( $active_stays, fn( $s ) =>
            ! empty( $s['preferences_or_allergies'] ) || ! empty( $s['major_illness_history'] )
        );
        $special_care = array_values( $special_care );

        return [
            'active_count'          => count( $active_stays ),
            'kennel_capacity'       => $kennel_total,
            'active_stays'          => self::simplify_stays( $active_stays ),
            'arrivals_today'        => self::simplify_stays( $arrivals_today ),
            'departures_today'      => self::simplify_stays( $departures_today ),
            'arrivals_tomorrow'     => self::simplify_stays( $arrivals_tomorrow ),
            'departures_tomorrow'   => self::simplify_stays( $departures_tomorrow ),
            'overstays'             => self::simplify_stays( $overstays ),
            'arrived_today_actual'  => self::simplify_stays( $arrived_today ),
            'departed_today_actual' => self::simplify_stays( $completed_today ),
            'on_medication'         => self::simplify_stays( $on_medication, [ 'medication_detail' ] ),
            'special_care'          => self::simplify_stays( $special_care, [ 'preferences_or_allergies', 'major_illness_history' ] ),
        ];
    }

    // ── Tasks data ─────────────────────────────────────────────────────────────

    private static function tasks_data( int $branch_id, string $today ): array {
        global $wpdb;
        $tasks_t = "{$wpdb->prefix}opb_tasks";

        // Open tasks (includes In Progress)
        $open = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, priority, due_date, assignee, status
             FROM {$tasks_t}
             WHERE branch_id = %d AND status IN ('Open','In Progress')
             ORDER BY FIELD(priority,'High','Medium','Low') ASC, due_date ASC",
            $branch_id
        ), ARRAY_A ) ?? [];

        // Overdue: due_date < today AND status != Done
        $overdue = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, priority, due_date, assignee, status
             FROM {$tasks_t}
             WHERE branch_id = %d
               AND status IN ('Open','In Progress')
               AND due_date IS NOT NULL
               AND due_date < %s
             ORDER BY due_date ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Due today
        $due_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, priority, due_date, assignee, status
             FROM {$tasks_t}
             WHERE branch_id = %d
               AND status IN ('Open','In Progress')
               AND due_date = %s
             ORDER BY FIELD(priority,'High','Medium','Low') ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Recently completed (last 24 hours)
        $recently_completed = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, priority, assignee, updated_at
             FROM {$tasks_t}
             WHERE branch_id = %d
               AND status = 'Done'
               AND updated_at >= DATE_SUB(%s, INTERVAL 24 HOUR)
             ORDER BY updated_at DESC
             LIMIT 20",
            $branch_id, current_time( 'mysql' )
        ), ARRAY_A ) ?? [];

        // Unassigned open tasks
        $unassigned = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, priority, due_date, status
             FROM {$tasks_t}
             WHERE branch_id = %d
               AND status IN ('Open','In Progress')
               AND (assignee IS NULL OR assignee = '')
             ORDER BY FIELD(priority,'High','Medium','Low') ASC",
            $branch_id
        ), ARRAY_A ) ?? [];

        return [
            'open_count'              => count( $open ),
            'overdue_count'           => count( $overdue ),
            'due_today_count'         => count( $due_today ),
            'recently_completed'      => $recently_completed,
            'unassigned_count'        => count( $unassigned ),
            'open_tasks'              => $open,
            'overdue_tasks'           => $overdue,
            'due_today_tasks'         => $due_today,
            'unassigned_tasks'        => $unassigned,
        ];
    }

    // ── Clients data ───────────────────────────────────────────────────────────

    private static function clients_data( int $branch_id, string $today ): array {
        global $wpdb;
        $clients_t = "{$wpdb->prefix}opb_clients";

        // New clients (created today)
        $new_today = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, phone, tc_accepted, onboarding_date
             FROM {$clients_t}
             WHERE home_branch_id = %d
               AND DATE(created_at) = %s
               AND status = 'active'
             ORDER BY created_at DESC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // Clients without T&C acceptance who have active bookings
        $missing_tc = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT c.id, c.name, c.phone
             FROM {$clients_t} c
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.client_id = c.id
             JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id = bk.id
             WHERE c.home_branch_id = %d
               AND c.status = 'active'
               AND c.tc_accepted = 0
               AND bs.status IN ('Active','Upcoming')
             LIMIT 20",
            $branch_id
        ), ARRAY_A ) ?? [];

        return [
            'new_today_count' => count( $new_today ),
            'new_today'       => $new_today,
            'missing_tc'      => $missing_tc,
            'missing_tc_count'=> count( $missing_tc ),
        ];
    }

    // ── Invoices data ──────────────────────────────────────────────────────────

    private static function invoices_data( int $branch_id, string $today ): array {
        global $wpdb;
        $inv_t = "{$wpdb->prefix}opb_invoices";
        $bk_t  = "{$wpdb->prefix}opb_bookings";
        $cli_t = "{$wpdb->prefix}opb_clients";

        // Generated today
        $generated_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$inv_t}
             WHERE branch_id = %d AND DATE(created_at) = %s",
            $branch_id, $today
        ) );

        // Unpaid invoices
        $unpaid = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id, i.invoice_date, i.revenue, i.due, c.name AS client_name
             FROM {$inv_t} i
             JOIN {$bk_t} bk ON bk.id = i.booking_id
             JOIN {$cli_t} c ON c.id = bk.client_id
             WHERE i.branch_id = %d
               AND i.payment_status IN ('Unpaid','Partially paid')
             ORDER BY i.invoice_date ASC
             LIMIT 50",
            $branch_id
        ), ARRAY_A ) ?? [];

        // Overdue invoices (unpaid and invoice date > 7 days ago)
        $overdue_date = date( 'Y-m-d', strtotime( $today . ' -7 days' ) );
        $overdue = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id, i.invoice_date, i.revenue, i.due, c.name AS client_name
             FROM {$inv_t} i
             JOIN {$bk_t} bk ON bk.id = i.booking_id
             JOIN {$cli_t} c ON c.id = bk.client_id
             WHERE i.branch_id = %d
               AND i.payment_status IN ('Unpaid','Partially paid')
               AND i.invoice_date < %s
             ORDER BY i.invoice_date ASC
             LIMIT 30",
            $branch_id, $overdue_date
        ), ARRAY_A ) ?? [];

        // Invoices with outstanding amount (total due > 0)
        $total_outstanding = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(due), 0)
             FROM {$inv_t}
             WHERE branch_id = %d
               AND payment_status IN ('Unpaid','Partially paid')",
            $branch_id
        ) );

        return [
            'generated_today'     => $generated_today,
            'unpaid_count'        => count( $unpaid ),
            'unpaid_invoices'     => $unpaid,
            'overdue_count'       => count( $overdue ),
            'overdue_invoices'    => $overdue,
            'total_outstanding'   => round( $total_outstanding, 2 ),
        ];
    }

    // ── Payments data ──────────────────────────────────────────────────────────

    private static function payments_data( int $branch_id, string $today ): array {
        global $wpdb;
        $pay_t = "{$wpdb->prefix}opb_payments";
        $inv_t = "{$wpdb->prefix}opb_invoices";
        $bk_t  = "{$wpdb->prefix}opb_bookings";
        $cli_t = "{$wpdb->prefix}opb_clients";

        // Payments received today
        $today_payments = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.amount, p.mode, p.paid_at,
                    c.name AS client_name
             FROM {$pay_t} p
             JOIN {$inv_t} i ON i.id = p.invoice_id
             JOIN {$bk_t} bk ON bk.id = i.booking_id
             JOIN {$cli_t} c ON c.id = bk.client_id
             WHERE p.branch_id = %d
               AND DATE(p.paid_at) = %s
             ORDER BY p.paid_at DESC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        $today_total = array_sum( array_column( $today_payments, 'amount' ) );

        // Invoices with partial payments (paid > 0 but not fully paid)
        $partial = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id, i.revenue, i.paid, i.due, c.name AS client_name
             FROM {$inv_t} i
             JOIN {$bk_t} bk ON bk.id = i.booking_id
             JOIN {$cli_t} c ON c.id = bk.client_id
             WHERE i.branch_id = %d
               AND i.payment_status = 'Partially paid'
             ORDER BY i.invoice_date ASC
             LIMIT 20",
            $branch_id
        ), ARRAY_A ) ?? [];

        return [
            'received_today_count' => count( $today_payments ),
            'received_today_total' => round( $today_total, 2 ),
            'received_today'       => $today_payments,
            'partial_count'        => count( $partial ),
            'partial_payments'     => $partial,
        ];
    }

    // ── Expenses data ──────────────────────────────────────────────────────────

    private static function expenses_data( int $branch_id, string $today ): array {
        global $wpdb;
        $exp_t = "{$wpdb->prefix}opb_expenses";

        $today_expenses = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, description, amount, category, mode, expense_at
             FROM {$exp_t}
             WHERE branch_id = %d AND DATE(expense_at) = %s
             ORDER BY expense_at DESC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        $today_total = array_sum( array_column( $today_expenses, 'amount' ) );

        return [
            'today_count' => count( $today_expenses ),
            'today_total' => round( $today_total, 2 ),
            'today_list'  => $today_expenses,
        ];
    }

    // ── Operational exceptions ─────────────────────────────────────────────────

    private static function exception_data( int $branch_id, string $today ): array {
        global $wpdb;
        $stays_t    = "{$wpdb->prefix}opb_booking_stays";
        $bookings_t = "{$wpdb->prefix}opb_bookings";
        $pets_t     = "{$wpdb->prefix}opb_pets";
        $clients_t  = "{$wpdb->prefix}opb_clients";

        // Pets currently boarded with missing or no vaccination record
        $unvaccinated_boarded = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.name AS pet_name, p.pet_type, p.vaccination_status,
                    c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.status = 'Active'
               AND p.vaccination_status IN ('Not vaccinated','Unknown')
             ORDER BY p.vaccination_status ASC",
            $branch_id
        ), ARRAY_A ) ?? [];

        // Boarding stays with missing check-in slot info but marked as Upcoming for today
        $missing_slot = $wpdb->get_results( $wpdb->prepare(
            "SELECT bs.id, bs.check_in_date, bs.boarding_type,
                    p.name AS pet_name, c.name AS client_name
             FROM {$stays_t} bs
             JOIN {$bookings_t} bk ON bk.id = bs.booking_id
             JOIN {$pets_t} p ON p.id = bs.pet_id
             JOIN {$clients_t} c ON c.id = bk.client_id
             WHERE bk.branch_id = %d
               AND bs.check_in_date = %s
               AND bs.status = 'Upcoming'
               AND (bs.check_in_slot IS NULL OR bs.check_in_slot = '')
             ORDER BY p.name ASC",
            $branch_id, $today
        ), ARRAY_A ) ?? [];

        // No-show stays (expected today but status still Upcoming and past noon)
        $hour = (int) current_time( 'G' );
        $no_shows = [];
        if ( $hour >= 15 ) {
            $no_shows = $wpdb->get_results( $wpdb->prepare(
                "SELECT bs.id, bs.check_in_date, bs.boarding_type,
                        p.name AS pet_name, c.name AS client_name, c.phone AS client_phone
                 FROM {$stays_t} bs
                 JOIN {$bookings_t} bk ON bk.id = bs.booking_id
                 JOIN {$pets_t} p ON p.id = bs.pet_id
                 JOIN {$clients_t} c ON c.id = bk.client_id
                 WHERE bk.branch_id = %d
                   AND bs.check_in_date = %s
                   AND bs.boarding_type = 'DAY'
                   AND bs.status = 'Upcoming'
                 ORDER BY p.name ASC",
                $branch_id, $today
            ), ARRAY_A ) ?? [];
        }

        return [
            'unvaccinated_boarded'     => $unvaccinated_boarded,
            'unvaccinated_count'       => count( $unvaccinated_boarded ),
            'missing_arrival_slot'     => $missing_slot,
            'missing_arrival_slot_count' => count( $missing_slot ),
            'potential_no_shows'       => $no_shows,
            'potential_no_show_count'  => count( $no_shows ),
        ];
    }

    // ── Cross-branch totals ────────────────────────────────────────────────────

    private static function compute_totals( array $branches, string $brief_type ): array {
        $totals = [];

        if ( in_array( $brief_type, [ 'morning', 'evening' ], true ) ) {
            $totals['total_active']             = array_sum( array_column( array_column( $branches, 'boarding' ), 'active_count' ) );
            $totals['total_arrivals_today']     = array_sum( array_map( fn( $b ) => count( $b['boarding']['arrivals_today'] ?? [] ), $branches ) );
            $totals['total_departures_today']   = array_sum( array_map( fn( $b ) => count( $b['boarding']['departures_today'] ?? [] ), $branches ) );
            $totals['total_arrivals_tomorrow']  = array_sum( array_map( fn( $b ) => count( $b['boarding']['arrivals_tomorrow'] ?? [] ), $branches ) );
            $totals['total_departures_tomorrow']= array_sum( array_map( fn( $b ) => count( $b['boarding']['departures_tomorrow'] ?? [] ), $branches ) );
            $totals['total_overstays']          = array_sum( array_map( fn( $b ) => count( $b['boarding']['overstays'] ?? [] ), $branches ) );
            $totals['total_on_medication']      = array_sum( array_map( fn( $b ) => count( $b['boarding']['on_medication'] ?? [] ), $branches ) );
            $totals['total_special_care']       = array_sum( array_map( fn( $b ) => count( $b['boarding']['special_care'] ?? [] ), $branches ) );
            $totals['total_open_tasks']         = array_sum( array_column( array_column( $branches, 'tasks' ), 'open_count' ) );
            $totals['total_overdue_tasks']      = array_sum( array_column( array_column( $branches, 'tasks' ), 'overdue_count' ) );
            $totals['total_unassigned_tasks']   = array_sum( array_column( array_column( $branches, 'tasks' ), 'unassigned_count' ) );
            $totals['total_unvaccinated']       = array_sum( array_column( array_column( $branches, 'exceptions' ), 'unvaccinated_count' ) );
        }

        if ( $brief_type === 'evening' ) {
            $totals['total_arrived_today']   = array_sum( array_map( fn( $b ) => count( $b['boarding']['arrived_today_actual'] ?? [] ), $branches ) );
            $totals['total_departed_today']  = array_sum( array_map( fn( $b ) => count( $b['boarding']['departed_today_actual'] ?? [] ), $branches ) );
        }

        if ( $brief_type === 'accounts' ) {
            $totals['total_unpaid']           = array_sum( array_column( array_column( $branches, 'invoices' ), 'unpaid_count' ) );
            $totals['total_overdue']          = array_sum( array_column( array_column( $branches, 'invoices' ), 'overdue_count' ) );
            $totals['total_outstanding']      = array_sum( array_column( array_column( $branches, 'invoices' ), 'total_outstanding' ) );
            $totals['total_payments_today']   = array_sum( array_column( array_column( $branches, 'payments' ), 'received_today_total' ) );
            $totals['total_expenses_today']   = array_sum( array_column( array_column( $branches, 'expenses' ), 'today_total' ) );
            $totals['total_partial']          = array_sum( array_column( array_column( $branches, 'payments' ), 'partial_count' ) );
        }

        return $totals;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Simplify stay rows to essential display fields only.
     * @param array[] $stays  Full stay rows
     * @param string[] $extras  Additional fields to include
     * @return array[]
     */
    private static function simplify_stays( array $stays, array $extras = [] ): array {
        $out = [];
        foreach ( $stays as $s ) {
            $row = [
                'pet_name'      => $s['pet_name'] ?? '',
                'pet_type'      => $s['pet_type'] ?? '',
                'client_name'   => $s['client_name'] ?? '',
                'check_in_date' => $s['check_in_date'] ?? '',
                'check_out_date'=> $s['check_out_date'] ?? '',
                'boarding_type' => $s['boarding_type'] ?? '',
            ];
            foreach ( $extras as $field ) {
                if ( isset( $s[ $field ] ) ) {
                    $row[ $field ] = $s[ $field ];
                }
            }
            if ( isset( $s['actual_check_in_at'] ) )  $row['checked_in_at']  = $s['actual_check_in_at'];
            if ( isset( $s['actual_check_out_at'] ) )  $row['checked_out_at'] = $s['actual_check_out_at'];
            if ( isset( $s['client_phone'] ) )          $row['client_phone']   = $s['client_phone'];
            if ( isset( $s['vaccination_status'] ) )    $row['vaccination_status'] = $s['vaccination_status'];
            $out[] = $row;
        }
        return $out;
    }
}
