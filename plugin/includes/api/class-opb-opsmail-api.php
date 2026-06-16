<?php
/**
 * OPB_Opsmail_API
 *
 * REST endpoints for OPSMAIL queue visibility.
 *
 * GET  /opb/v1/opsmail/queue         — Paginated queue list  (super admin only)
 * GET  /opb/v1/opsmail/stats         — Queue summary counts  (super admin only)
 * POST /opb/v1/opsmail/queue/{id}/acknowledge — Mark event ACKNOWLEDGED (super admin only)
 *
 * Permission: manage_options (WP administrator / opb_super_admin)
 */
class OPB_Opsmail_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/opsmail/queue', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_queue' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/opsmail/stats', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_stats' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/opsmail/queue/(?P<id>\d+)/acknowledge', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'acknowledge' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );
    }

    public function super_admin_only( WP_REST_Request $r ): bool|WP_Error {
        return $this->permission_manage( 'manage_options', $r );
    }

    // ── Queue List ─────────────────────────────────────────────────────────────

    public function get_queue( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $page      = max( 1, (int) ( $r->get_param('page')     ?? 1 ) );
        $per_page  = min( 100, (int) ( $r->get_param('per_page') ?? 50 ) );
        $offset    = ( $page - 1 ) * $per_page;

        $status     = sanitize_text_field( $r->get_param('status')     ?? '' );
        $event_type = sanitize_text_field( $r->get_param('event_type') ?? '' );
        $date_from  = sanitize_text_field( $r->get_param('date_from')  ?? '' );
        $date_to    = sanitize_text_field( $r->get_param('date_to')    ?? '' );
        $search     = sanitize_text_field( $r->get_param('search')     ?? '' );

        $where = [ '1=1' ];
        $args  = [];

        if ( $status ) {
            $where[] = 'q.status = %s';
            $args[]  = $status;
        }
        if ( $event_type ) {
            $where[] = 'q.event_type = %s';
            $args[]  = $event_type;
        }
        if ( $date_from ) {
            $where[] = 'DATE(q.created_at) >= %s';
            $args[]  = $date_from;
        }
        if ( $date_to ) {
            $where[] = 'DATE(q.created_at) <= %s';
            $args[]  = $date_to;
        }
        if ( $search ) {
            $like    = '%' . esc_sql( $search ) . '%';
            $where[] = '(q.subject LIKE %s OR q.summary LIKE %s OR q.event_type LIKE %s)';
            $args    = array_merge( $args, [ $like, $like, $like ] );
        }

        $where_sql = implode( ' AND ', $where );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_opsmail_queue q WHERE {$where_sql}",
            ...$args
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT q.id, q.event_uuid, q.event_type, q.entity_type, q.entity_id,
                    q.branch_id, q.origin_type, q.priority, q.subject, q.summary,
                    q.recipient_email, q.status, q.mail_attempts, q.last_error,
                    q.created_at, q.sent_at,
                    b.name AS branch_name
             FROM {$wpdb->prefix}opb_opsmail_queue q
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = q.branch_id
             WHERE {$where_sql}
             ORDER BY q.id DESC
             LIMIT %d OFFSET %d",
            ...[ ...$args, $per_page, $offset ]
        ), ARRAY_A );

        return $this->success( $this->paginate( $rows ?? [], $total, $page, $per_page ) );
    }

    // ── Stats ──────────────────────────────────────────────────────────────────

    public function get_stats( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $counts = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt
             FROM {$wpdb->prefix}opb_opsmail_queue
             GROUP BY status",
            ARRAY_A
        );

        $by_status = [
            'PENDING'      => 0,
            'SENT'         => 0,
            'FAILED'       => 0,
            'ACKNOWLEDGED' => 0,
        ];
        foreach ( $counts ?? [] as $row ) {
            if ( isset( $by_status[ $row['status'] ] ) ) {
                $by_status[ $row['status'] ] = (int) $row['cnt'];
            }
        }

        $by_event = $wpdb->get_results(
            "SELECT event_type, COUNT(*) AS cnt
             FROM {$wpdb->prefix}opb_opsmail_queue
             GROUP BY event_type
             ORDER BY cnt DESC",
            ARRAY_A
        ) ?? [];

        $recent_failed = $wpdb->get_results(
            "SELECT id, event_type, subject, last_error, created_at
             FROM {$wpdb->prefix}opb_opsmail_queue
             WHERE status = 'FAILED'
             ORDER BY id DESC
             LIMIT 5",
            ARRAY_A
        ) ?? [];

        return $this->success( [
            'by_status'     => $by_status,
            'total'         => array_sum( $by_status ),
            'by_event'      => $by_event,
            'recent_failed' => $recent_failed,
            'opsmail_enabled' => OPB_Opsmail::is_enabled(),
            'inbox_configured' => OPB_Opsmail::inbox_email() !== '',
        ] );
    }

    // ── Acknowledge ────────────────────────────────────────────────────────────

    public function acknowledge( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = (int) $r['id'];

        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_opsmail_queue WHERE id = %d",
            $id
        ) );
        if ( ! $row ) {
            return $this->error( 'not_found', 'Queue event not found.', 404 );
        }

        $wpdb->update(
            "{$wpdb->prefix}opb_opsmail_queue",
            [ 'status' => OPB_Opsmail::STATUS_ACKNOWLEDGED ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $this->success( [ 'id' => $id, 'status' => OPB_Opsmail::STATUS_ACKNOWLEDGED ] );
    }
}
