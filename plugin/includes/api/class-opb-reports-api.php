<?php
class OPB_Reports_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/reports', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_report' ], 'permission_callback' => fn($r) => $this->permission_manage('opb_view_reports', $r) ],
        ]);
    }

    public function get_report( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_view_reports', $r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $today     = current_time('Y-m-d');
        $from      = sanitize_text_field($r->get_param('from') ?: date('Y-m-01', strtotime($today)));
        $to        = sanitize_text_field($r->get_param('to')   ?: $today);
        $branch_id = $this->branch_filter((int)($r->get_param('branch_id') ?? 0));

        // WHERE fragments
        $inv_w  = $branch_id ? $wpdb->prepare(' AND i.branch_id=%d', $branch_id) : '';
        $exp_w  = $branch_id ? $wpdb->prepare(' AND e.branch_id=%d', $branch_id) : '';
        $bk_w   = $branch_id ? $wpdb->prepare(' AND bk.branch_id=%d', $branch_id) : '';

        // ── Revenue by day ────────────────────────────────────────────────────
        $rev_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(i.invoice_date) as day,
                    COALESCE(SUM(i.revenue),0) as revenue
             FROM {$wpdb->prefix}opb_invoices i
             WHERE i.invoice_date >= %s AND i.invoice_date <= %s $inv_w
             GROUP BY DATE(i.invoice_date)
             ORDER BY day ASC",
            $from, $to
        ), ARRAY_A);

        $exp_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(e.expense_at) as day,
                    COALESCE(SUM(e.amount),0) as expense
             FROM {$wpdb->prefix}opb_expenses e
             WHERE DATE(e.expense_at) >= %s AND DATE(e.expense_at) <= %s $exp_w
             GROUP BY DATE(e.expense_at)
             ORDER BY day ASC",
            $from, $to
        ), ARRAY_A);

        // Merge into one timeline
        $rev_map = array_column($rev_rows,  'revenue', 'day');
        $exp_map = array_column($exp_rows, 'expense', 'day');
        $all_days = array_unique(array_merge(array_keys($rev_map), array_keys($exp_map)));
        sort($all_days);
        $revenue_by_day = [];
        foreach($all_days as $day){
            $revenue_by_day[] = [
                'day'     => $day,
                'revenue' => (float)($rev_map[$day] ?? 0),
                'expense' => (float)($exp_map[$day] ?? 0),
            ];
        }

        // ── Expenses by category ─────────────────────────────────────────────
        $expenses_by_category = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(e.category,''),'Uncategorised') as category,
                    COALESCE(SUM(e.amount),0) as total
             FROM {$wpdb->prefix}opb_expenses e
             WHERE DATE(e.expense_at) >= %s AND DATE(e.expense_at) <= %s $exp_w
             GROUP BY COALESCE(NULLIF(e.category,''),'Uncategorised')
             ORDER BY total DESC",
            $from, $to
        ), ARRAY_A);
        foreach($expenses_by_category as &$row){ $row['total']=(float)$row['total']; }

        // ── Revenue by branch ─────────────────────────────────────────────────
        $revenue_by_branch = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(b.name,'Unknown Branch') as branch,
                    COALESCE(SUM(i.revenue),0) as revenue,
                    COALESCE(SUM(i.due),0) as outstanding
             FROM {$wpdb->prefix}opb_invoices i
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             WHERE i.invoice_date >= %s AND i.invoice_date <= %s $inv_w
             GROUP BY i.branch_id, COALESCE(b.name,'Unknown Branch')
             ORDER BY revenue DESC",
            $from, $to
        ), ARRAY_A);
        foreach($revenue_by_branch as &$row){
            $row['revenue']     = (float)$row['revenue'];
            $row['outstanding'] = (float)$row['outstanding'];
        }

        // ── Top clients ───────────────────────────────────────────────────────
        $top_clients = $wpdb->get_results($wpdb->prepare(
            "SELECT c.name, c.id as client_id,
                    COALESCE(SUM(i.revenue),0) as revenue,
                    COUNT(DISTINCT bk.id) as bookings
             FROM {$wpdb->prefix}opb_invoices i
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=i.booking_id
             JOIN {$wpdb->prefix}opb_clients  c  ON c.id=bk.client_id
             WHERE i.invoice_date >= %s AND i.invoice_date <= %s $inv_w
             GROUP BY c.id, c.name
             ORDER BY revenue DESC
             LIMIT 10",
            $from, $to
        ), ARRAY_A);
        foreach($top_clients as &$row){
            $row['revenue']  = (float)$row['revenue'];
            $row['bookings'] = (int)$row['bookings'];
        }

        // ── Occupancy by week ─────────────────────────────────────────────────
        // Count nights booked per week vs total kennel capacity
        $capacity = (int)$wpdb->get_var(
            "SELECT COALESCE(SUM(capacity),0) FROM {$wpdb->prefix}opb_branches WHERE is_active=1"
        ) ?: 1;

        $occ_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT YEARWEEK(bs.check_in_date,1) as yw,
                    MIN(bs.check_in_date) as week_start,
                    COUNT(*) as nights
             FROM {$wpdb->prefix}opb_booking_stays bs
             JOIN {$wpdb->prefix}opb_bookings bk ON bk.id=bs.booking_id
             WHERE bs.check_in_date >= %s AND bs.check_in_date <= %s AND bk.status != 'Cancelled' $bk_w
             GROUP BY yw
             ORDER BY yw ASC",
            $from, $to
        ), ARRAY_A);

        $occupancy_by_week = [];
        foreach($occ_rows as $row){
            $occupancy_by_week[] = [
                'week'  => $row['week_start'],
                'rate'  => round(min(100, ((int)$row['nights'] / ($capacity * 7)) * 100), 1),
                'nights'=> (int)$row['nights'],
            ];
        }

        // ── Summary totals ────────────────────────────────────────────────────
        // Note: aliases (i, e, bk) must match those in $inv_w / $exp_w / $bk_w.
        $total_revenue = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(i.revenue),0) FROM {$wpdb->prefix}opb_invoices i
             WHERE i.invoice_date >= %s AND i.invoice_date <= %s $inv_w", $from, $to
        ));
        $total_expenses = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(e.amount),0) FROM {$wpdb->prefix}opb_expenses e
             WHERE DATE(e.expense_at) >= %s AND DATE(e.expense_at) <= %s $exp_w", $from, $to
        ));
        $total_bookings = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings bk
             WHERE DATE(bk.created_at) >= %s AND DATE(bk.created_at) <= %s $bk_w", $from, $to
        ));
        $total_outstanding = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(i.due),0) FROM {$wpdb->prefix}opb_invoices i
             WHERE i.invoice_date >= %s AND i.invoice_date <= %s $inv_w", $from, $to
        ));

        return $this->success([
            'from'                => $from,
            'to'                  => $to,
            'summary'             => [
                'total_revenue'     => $total_revenue,
                'total_expenses'    => $total_expenses,
                'net_profit'        => $total_revenue - $total_expenses,
                'total_bookings'    => $total_bookings,
                'total_outstanding' => $total_outstanding,
            ],
            'revenue_by_day'      => $revenue_by_day,
            'expenses_by_category'=> $expenses_by_category,
            'revenue_by_branch'   => $revenue_by_branch,
            'occupancy_by_week'   => $occupancy_by_week,
            'top_clients'         => $top_clients,
        ]);
    }
}
