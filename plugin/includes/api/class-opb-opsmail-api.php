<?php
/**
 * OPB_Opsmail_API
 *
 * REST endpoints for the OPSMAIL Operational Intelligence Repository.
 *
 * GET  /opb/v1/opsmail/queue              — Paginated event list   (super admin only)
 * GET  /opb/v1/opsmail/stats             — Repository summary      (super admin only)
 * POST /opb/v1/opsmail/queue/{id}/acknowledge — Mark mail_status ACKNOWLEDGED (super admin only)
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
            $where[] = 'q.mail_status = %s';
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
            "SELECT q.id, q.event_uuid, q.event_type, q.source_system,
                    q.entity_type, q.entity_id, q.branch_id,
                    q.origin_type, q.priority, q.subject, q.summary,
                    q.recipient_email, q.mail_status, q.telegram_status,
                    q.mail_attempts, q.telegram_attempts,
                    q.last_error, q.created_at, q.sent_at, q.telegram_sent_at,
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

        // Mail channel status counts
        $mail_counts = $wpdb->get_results(
            "SELECT mail_status, COUNT(*) AS cnt
             FROM {$wpdb->prefix}opb_opsmail_queue
             GROUP BY mail_status",
            ARRAY_A
        ) ?? [];

        $by_mail_status = [
            'PENDING'      => 0,
            'SENT'         => 0,
            'FAILED'       => 0,
            'ACKNOWLEDGED' => 0,
        ];
        foreach ( $mail_counts as $row ) {
            if ( isset( $by_mail_status[ $row['mail_status'] ] ) ) {
                $by_mail_status[ $row['mail_status'] ] = (int) $row['cnt'];
            }
        }

        // Telegram channel status counts (all PENDING until consumer is implemented)
        $telegram_counts = $wpdb->get_results(
            "SELECT telegram_status, COUNT(*) AS cnt
             FROM {$wpdb->prefix}opb_opsmail_queue
             GROUP BY telegram_status",
            ARRAY_A
        ) ?? [];

        $by_telegram_status = [ 'PENDING' => 0, 'SENT' => 0, 'FAILED' => 0 ];
        foreach ( $telegram_counts as $row ) {
            if ( isset( $by_telegram_status[ $row['telegram_status'] ] ) ) {
                $by_telegram_status[ $row['telegram_status'] ] = (int) $row['cnt'];
            }
        }

        // Event type breakdown
        $by_event = $wpdb->get_results(
            "SELECT event_type, COUNT(*) AS cnt
             FROM {$wpdb->prefix}opb_opsmail_queue
             GROUP BY event_type
             ORDER BY cnt DESC",
            ARRAY_A
        ) ?? [];

        // Recent mail failures for the alert panel
        $recent_failed = $wpdb->get_results(
            "SELECT id, event_type, subject, last_error, created_at
             FROM {$wpdb->prefix}opb_opsmail_queue
             WHERE mail_status = 'FAILED'
             ORDER BY id DESC
             LIMIT 5",
            ARRAY_A
        ) ?? [];

        return $this->success( [
            'by_mail_status'     => $by_mail_status,
            'by_telegram_status' => $by_telegram_status,
            'total'              => array_sum( $by_mail_status ),
            'by_event'           => $by_event,
            'recent_failed'      => $recent_failed,
            'opsmail_enabled'    => OPB_Opsmail::is_enabled(),
            'inbox_configured'   => OPB_Opsmail::inbox_email() !== '',
        ] );
    }

    // ── Acknowledge ────────────────────────────────────────────────────────────

    /**
     * Mark an event as ACKNOWLEDGED on the mail channel.
     *
     * Acknowledge is a human "read receipt" — the operator has seen and acted
     * on the event. It has no side effects: no email is sent, no task is
     * created, no workflow is triggered. OPSMAIL is awareness only.
     */
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
            [ 'mail_status' => OPB_Opsmail::STATUS_ACKNOWLEDGED ],
            [ 'id' => $id ],
            [ '%s' ],
            [ '%d' ]
        );

        return $this->success( [ 'id' => $id, 'mail_status' => OPB_Opsmail::STATUS_ACKNOWLEDGED ] );
    }
}
