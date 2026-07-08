<?php
class OPB_Dashboard_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/dashboard', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_dashboard' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ]);
    }

    public function get_dashboard( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter((int)($r->get_param('branch_id')??0));
        $today     = current_time('Y-m-d');
        $month_start = date('Y-m-01', strtotime($today));

        // ── Operational Start Date ────────────────────────────────────────────
        // Reads from opb_customizations. Empty string = no filter (legacy behaviour).
        $op_start = OPB_Customizations::get('operational_start_date');
        $op_start = ( $op_start && preg_match('/^\d{4}-\d{2}-\d{2}$/', $op_start) ) ? $op_start : '';

        // For revenue_month: lower bound is the later of month_start and op_start
        $rev_lower = ( $op_start && $op_start > $month_start ) ? $op_start : $month_start;

        $b_where  = $branch_id ? $wpdb->prepare(' AND bk.branch_id=%d',$branch_id) : '';
        $bs_where = $branch_id ? $wpdb->prepare(' AND bs.booking_id IN (SELECT id FROM '.$wpdb->prefix.'opb_bookings WHERE branch_id=%d)',$branch_id) : '';
        $inv_where= $branch_id ? $wpdb->prepare(' AND i.branch_id=%d',$branch_id) : '';
        $exp_where= $branch_id ? $wpdb->prepare(' AND e.branch_id=%d',$branch_id) : '';
        $task_where=$branch_id? $wpdb->prepare(' AND t.branch_id=%d',$branch_id) : '';

        // ── KPIs ─────────────────────────────────────────────────────────────

        // Active stays — current operational status, no date-range filter needed.
        // JOIN opb_bookings to exclude stays belonging to cancelled bookings.
        $active_stays = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             WHERE bs.status='Active' AND bk.status != 'Cancelled'$b_where"
        );

        // Today's check-ins/outs — today-only, unaffected by op_start
        // JOIN opb_bookings to exclude cancelled bookings from operational KPIs.
        $checkins_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             WHERE bs.check_in_date=%s AND bs.status IN ('Upcoming','Active') AND bk.status != 'Cancelled'$b_where",
            $today
        ));
        $checkouts_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             WHERE bs.check_out_date=%s AND bs.status IN ('Active','Completed') AND bk.status != 'Cancelled'$b_where",
            $today
        ));

        // Revenue this month — bounded by op_start if set and op_start is within this month
        $revenue_month = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(i.revenue),0) FROM {$wpdb->prefix}opb_invoices i WHERE i.invoice_date>=%s AND i.invoice_date<=%s$inv_where",$rev_lower,$today
        ));

        // Outstanding — apply op_start as lower bound when set
        if ( $op_start ) {
            $outstanding = (float)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(i.due),0) FROM {$wpdb->prefix}opb_invoices i WHERE i.due>0 AND i.invoice_date>=%s$inv_where", $op_start
            ));
        } else {
            $outstanding = (float)$wpdb->get_var(
                "SELECT COALESCE(SUM(i.due),0) FROM {$wpdb->prefix}opb_invoices i WHERE i.due>0$inv_where"
            );
        }

        $current_user_display_name = wp_get_current_user()->display_name;
        $tasks_due = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_tasks t
             WHERE t.status IN ('Open','Pending','In Progress')
               AND t.assignee=%s$task_where",
            $current_user_display_name
        ));

        // New inquiries — pipeline items awaiting staff action (not branch-scoped)
        $new_inquiries = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_inquiries
             WHERE status IN ('NEW','READY_FOR_REVIEW')"
        );

        // Today's check-ins
        $todays_checkins = $wpdb->get_results($wpdb->prepare(
            "SELECT bs.id as stay_id, bs.check_in_date, bs.check_in_slot, bs.kennel, bs.status,
                    p.name as pet_name, p.breed,
                    c.name as client_name, c.phone as client_phone,
                    bk.id as booking_id
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             WHERE bs.check_in_date=%s AND bs.status IN ('Upcoming','Active') AND bk.status != 'Cancelled'$b_where
             ORDER BY bs.check_in_slot,bk.id",$today
        ),ARRAY_A);

        // Today's check-outs
        $todays_checkouts = $wpdb->get_results($wpdb->prepare(
            "SELECT bs.id as stay_id, bs.check_out_date, bs.check_out_slot, bs.status,
                    p.name as pet_name, p.breed,
                    c.name as client_name, i.due, i.payment_status,
                    bk.id as booking_id
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             LEFT JOIN {$wpdb->prefix}opb_invoices i ON i.booking_id=bk.id
             WHERE bs.check_out_date=%s AND bs.status IN ('Active','Completed') AND bk.status != 'Cancelled'$b_where
             ORDER BY bs.check_out_slot,bk.id",$today
        ),ARRAY_A);

        // Open tasks
        $open_tasks_sql = "SELECT t.id, t.title, t.priority, t.due_date, t.assignee, t.status
             FROM {$wpdb->prefix}opb_tasks t
             WHERE t.status!='Done'$task_where
             ORDER BY FIELD(t.priority,'High','Medium','Low'), t.due_date ASC
             LIMIT 5";
        $open_tasks = $wpdb->get_results( $open_tasks_sql, ARRAY_A );

        // Today's pet birthdays — single join, no N+1
        $pet_birthdays_raw = $wpdb->get_results($wpdb->prepare(
            "SELECT p.name as pet_name,
                    c.name as client_name,
                    YEAR(%s) - YEAR(p.birthday) as age
             FROM {$wpdb->prefix}opb_pets p
             JOIN {$wpdb->prefix}opb_clients c ON c.id = p.client_id
             WHERE p.birthday IS NOT NULL
               AND MONTH(p.birthday) = MONTH(%s)
               AND DAY(p.birthday)   = DAY(%s)
             ORDER BY p.name ASC",
            $today, $today, $today
        ), ARRAY_A);

        $pet_birthdays = array_map(function($row) {
            return [
                'pet_name'    => $row['pet_name'],
                'client_name' => $row['client_name'],
                'age'         => (int)$row['age'],
            ];
        }, $pet_birthdays_raw);

        return $this->success([
            'kpis' => [
                'active_stays'    => $active_stays,
                'checkins_today'  => $checkins_today,
                'checkouts_today' => $checkouts_today,
                'revenue_month'   => $revenue_month,
                'outstanding'     => $outstanding,
                'tasks_due'       => $tasks_due,
                'new_inquiries'   => $new_inquiries,
            ],
            'todays_checkins'       => $todays_checkins,
            'todays_checkouts'      => $todays_checkouts,
            'open_tasks'            => $open_tasks,
            'pet_birthdays'         => $pet_birthdays,
            'date'                  => $today,
            'operational_start_date'=> $op_start,
        ]);
    }
}
