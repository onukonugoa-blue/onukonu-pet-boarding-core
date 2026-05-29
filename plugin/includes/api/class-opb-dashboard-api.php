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

        $b_where = $branch_id ? $wpdb->prepare(' AND bk.branch_id=%d',$branch_id) : '';
        $bs_where= $branch_id ? $wpdb->prepare(' AND bs.booking_id IN (SELECT id FROM '.$wpdb->prefix.'opb_bookings WHERE branch_id=%d)',$branch_id) : '';
        $inv_where=$branch_id ? $wpdb->prepare(' AND i.branch_id=%d',$branch_id) : '';
        $exp_where=$branch_id ? $wpdb->prepare(' AND e.branch_id=%d',$branch_id) : '';
        $task_where=$branch_id? $wpdb->prepare(' AND t.branch_id=%d',$branch_id) : '';

        // KPIs
        $active_stays = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs WHERE bs.status='Active'$bs_where"
        );
        $checkins_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs WHERE bs.check_in_date=%s AND bs.status IN ('Upcoming','Active')$bs_where",$today
        ));
        $checkouts_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs WHERE bs.check_out_date=%s AND bs.status IN ('Active','Completed')$bs_where",$today
        ));
        $revenue_month = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(i.revenue),0) FROM {$wpdb->prefix}opb_invoices i WHERE i.invoice_date>=%s AND i.invoice_date<=%s$inv_where",$month_start,$today
        ));
        $outstanding = (float)$wpdb->get_var(
            "SELECT COALESCE(SUM(i.due),0) FROM {$wpdb->prefix}opb_invoices i WHERE i.due>0$inv_where"
        );
        $tasks_due = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_tasks t WHERE t.status!='Done' AND t.due_date<=%s$task_where",$today
        ));

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
             WHERE bs.check_in_date=%s AND bs.status IN ('Upcoming','Active')$b_where
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
             WHERE bs.check_out_date=%s AND bs.status IN ('Active','Completed')$b_where
             ORDER BY bs.check_out_slot,bk.id",$today
        ),ARRAY_A);

        // Open tasks
        $open_tasks = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.title, t.priority, t.due_date, t.assignee, t.status
             FROM {$wpdb->prefix}opb_tasks t
             WHERE t.status!='Done'$task_where
             ORDER BY FIELD(t.priority,'High','Medium','Low'), t.due_date ASC
             LIMIT 5",$today
        ),ARRAY_A);

        return $this->success([
            'kpis' => [
                'active_stays'    => $active_stays,
                'checkins_today'  => $checkins_today,
                'checkouts_today' => $checkouts_today,
                'revenue_month'   => $revenue_month,
                'outstanding'     => $outstanding,
                'tasks_due'       => $tasks_due,
            ],
            'todays_checkins'  => $todays_checkins,
            'todays_checkouts' => $todays_checkouts,
            'open_tasks'       => $open_tasks,
            'date'             => $today,
        ]);
    }
}
