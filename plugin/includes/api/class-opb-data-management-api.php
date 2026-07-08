<?php
/**
 * Data Management REST API — Super Administrator only.
 *
 * Provides controlled archive / restore operations for clients, pets,
 * bookings, and inquiries.  No hard-delete is implemented in Phase 1.
 *
 * All routes are gated behind permission_super_admin(), which requires
 * opb_manage_settings (exclusive to opb_super_admin) or manage_options
 * (WordPress administrator).
 *
 * GET  /opb/v1/admin/clients                    ?view=active|archived|all&search=&page=&per_page=
 * PUT  /opb/v1/admin/clients/{id}/archive        body: { reason? }
 * PUT  /opb/v1/admin/clients/{id}/restore
 *
 * GET  /opb/v1/admin/pets                        ?view=active|archived|all&search=&client_id=&page=&per_page=
 * PUT  /opb/v1/admin/pets/{id}/archive
 * PUT  /opb/v1/admin/pets/{id}/restore
 *
 * GET  /opb/v1/admin/bookings                    ?view=active|cancelled|all&search=&page=&per_page=
 * PUT  /opb/v1/admin/bookings/{id}/cancel
 * PUT  /opb/v1/admin/bookings/{id}/restore
 *
 * GET  /opb/v1/admin/inquiries                   ?view=active|archived|all&search=&page=&per_page=
 * PUT  /opb/v1/admin/inquiries/{id}/archive
 * PUT  /opb/v1/admin/inquiries/{id}/restore
 */
class OPB_Data_Management_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;
        $sa = [ $this, 'permission_super_admin' ];

        // ── Clients ───────────────────────────────────────────────────────────
        register_rest_route( $ns, '/admin/clients', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'list_clients' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/clients/(?P<id>\d+)/archive', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'archive_client' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/clients/(?P<id>\d+)/restore', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'restore_client' ], 'permission_callback' => $sa ],
        ]);

        // ── Pets ──────────────────────────────────────────────────────────────
        register_rest_route( $ns, '/admin/pets', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'list_pets' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/pets/(?P<id>\d+)/archive', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'archive_pet' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/pets/(?P<id>\d+)/restore', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'restore_pet' ], 'permission_callback' => $sa ],
        ]);

        // ── Bookings ──────────────────────────────────────────────────────────
        register_rest_route( $ns, '/admin/bookings', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'list_bookings' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/bookings/(?P<id>\d+)/cancel', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'cancel_booking' ], 'permission_callback' => [ $this, 'permission_booking_manager' ] ],
        ]);
        register_rest_route( $ns, '/admin/bookings/(?P<id>\d+)/restore', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'restore_booking' ], 'permission_callback' => [ $this, 'permission_booking_manager' ] ],
        ]);

        // ── Inquiries ─────────────────────────────────────────────────────────
        register_rest_route( $ns, '/admin/inquiries', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'list_inquiries' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/inquiries/(?P<id>\d+)/archive', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'archive_inquiry' ], 'permission_callback' => $sa ],
        ]);
        register_rest_route( $ns, '/admin/inquiries/(?P<id>\d+)/restore', [
            [ 'methods' => 'PUT', 'callback' => [ $this, 'restore_inquiry' ], 'permission_callback' => $sa ],
        ]);
    }

    // ── Permission gates ──────────────────────────────────────────────────────

    public function permission_super_admin( WP_REST_Request $r ): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Authentication required.', [ 'status' => 401 ] );
        }
        if ( ! ( current_user_can( 'opb_manage_settings' ) || current_user_can( 'manage_options' ) ) ) {
            return new WP_Error( 'rest_forbidden', 'Super Administrator access required.', [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * Permission gate for booking cancel / restore.
     * Accessible to super admins (opb_manage_settings / manage_options) and
     * regular booking managers (opb_manage_bookings).  No other capabilities
     * are widened — client / pet / inquiry archive routes remain super-admin-only.
     */
    public function permission_booking_manager( WP_REST_Request $r ): bool|WP_Error {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Authentication required.', [ 'status' => 401 ] );
        }
        if (
            current_user_can( 'opb_manage_settings' ) ||
            current_user_can( 'manage_options' )       ||
            current_user_can( 'opb_manage_bookings' )
        ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', 'Booking management access required.', [ 'status' => 403 ] );
    }

    // ── Clients ───────────────────────────────────────────────────────────────

    public function list_clients( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $view     = sanitize_text_field( $r->get_param('view') ?? 'active' );
        $search   = sanitize_text_field( $r->get_param('search') ?? '' );
        $page     = max(1,(int)($r->get_param('page')??1));
        $per_page = min(100,(int)($r->get_param('per_page')??50));
        $offset   = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];

        if ( $view === 'active' )       { $where[] = "c.status = 'active'"; }
        elseif ( $view === 'archived' ) { $where[] = "c.status = 'archived'"; }

        if ( $search ) {
            $like    = '%' . esc_sql($search) . '%';
            $where[] = '(c.name LIKE %s OR c.phone LIKE %s OR c.email LIKE %s)';
            $args    = array_merge($args, [$like, $like, $like]);
        }

        $where_sql = implode(' AND ', $where);

        $total = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_clients c WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id, c.name, c.phone, c.email, c.status, c.archive_reason,
                    c.home_branch_id, c.created_at,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets p WHERE p.client_id=c.id) AS pet_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings bk WHERE bk.client_id=c.id) AS booking_count
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=c.home_branch_id
             WHERE $where_sql
             ORDER BY c.name ASC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows, $total, $page, $per_page));
    }

    public function archive_client( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id     = (int)$r['id'];
        $d      = $r->get_json_params() ?? [];
        $reason = sanitize_textarea_field( $d['reason'] ?? '' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_clients WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Client not found.', 404);
        if ( $row->status === 'archived' ) return $this->error('already_archived', 'Client is already archived.');

        $wpdb->update(
            "{$wpdb->prefix}opb_clients",
            [ 'status' => 'archived', 'archive_reason' => $reason ?: null ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $this->success([ 'id' => $id, 'status' => 'archived' ]);
    }

    public function restore_client( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id = (int)$r['id'];

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_clients WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Client not found.', 404);
        if ( $row->status === 'active' ) return $this->error('already_active', 'Client is already active.');

        $wpdb->update(
            "{$wpdb->prefix}opb_clients",
            [ 'status' => 'active', 'archive_reason' => null ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $this->success([ 'id' => $id, 'status' => 'active' ]);
    }

    // ── Pets ──────────────────────────────────────────────────────────────────

    public function list_pets( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $view      = sanitize_text_field( $r->get_param('view') ?? 'active' );
        $search    = sanitize_text_field( $r->get_param('search') ?? '' );
        $client_id = (int)($r->get_param('client_id') ?? 0);
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];

        if ( $view === 'active' )       { $where[] = 'p.is_active = 1'; }
        elseif ( $view === 'archived' ) { $where[] = 'p.is_active = 0'; }

        if ( $client_id ) { $where[] = 'p.client_id = %d'; $args[] = $client_id; }

        if ( $search ) {
            $like    = '%' . esc_sql($search) . '%';
            $where[] = '(p.name LIKE %s OR c.name LIKE %s OR c.phone LIKE %s)';
            $args    = array_merge($args, [$like, $like, $like]);
        }

        $where_sql = implode(' AND ', $where);

        $total = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}opb_pets p
             JOIN {$wpdb->prefix}opb_clients c ON c.id=p.client_id
             WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.name, p.pet_type, p.breed, p.is_active,
                    p.client_id, p.created_at,
                    c.name AS client_name, c.phone AS client_phone,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_booking_stays bs WHERE bs.pet_id=p.id) AS stay_count
             FROM {$wpdb->prefix}opb_pets p
             JOIN {$wpdb->prefix}opb_clients c ON c.id=p.client_id
             WHERE $where_sql
             ORDER BY c.name ASC, p.name ASC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows, $total, $page, $per_page));
    }

    public function archive_pet( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_active FROM {$wpdb->prefix}opb_pets WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Pet not found.', 404);
        if ( (int)$row->is_active === 0 ) return $this->error('already_archived', 'Pet is already archived.');

        $wpdb->update("{$wpdb->prefix}opb_pets", ['is_active'=>0], ['id'=>$id], ['%d'], ['%d']);
        return $this->success([ 'id' => $id, 'is_active' => false ]);
    }

    public function restore_pet( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, is_active FROM {$wpdb->prefix}opb_pets WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Pet not found.', 404);
        if ( (int)$row->is_active === 1 ) return $this->error('already_active', 'Pet is already active.');

        $wpdb->update("{$wpdb->prefix}opb_pets", ['is_active'=>1], ['id'=>$id], ['%d'], ['%d']);
        return $this->success([ 'id' => $id, 'is_active' => true ]);
    }

    // ── Bookings ──────────────────────────────────────────────────────────────

    public function list_bookings( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $view      = sanitize_text_field( $r->get_param('view') ?? 'active' );
        $search    = sanitize_text_field( $r->get_param('search') ?? '' );
        $client_id = (int)( $r->get_param('client_id') ?? 0 );
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];

        if ( $view === 'active' )        { $where[] = "bk.status = 'Active'"; }
        elseif ( $view === 'cancelled' ) { $where[] = "bk.status = 'Cancelled'"; }

        if ( $client_id ) {
            $where[] = 'bk.client_id=%d';
            $args[]  = $client_id;
        }

        if ( $search ) {
            $like    = '%' . esc_sql($search) . '%';
            $where[] = '(c.name LIKE %s OR c.phone LIKE %s OR CAST(bk.id AS CHAR) LIKE %s)';
            $args    = array_merge($args, [$like, $like, $like]);
        }

        $where_sql = implode(' AND ', $where);

        $total = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}opb_bookings bk
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT bk.id, bk.booking_date, bk.status, bk.payment_status,
                    bk.total_billing_amount, bk.service_types, bk.client_id,
                    c.name AS client_name, c.phone AS client_phone,
                    b.name AS branch_name,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS pet_names,
                    MAX(bs.check_out_date) AS check_out_date
             FROM {$wpdb->prefix}opb_bookings bk
             JOIN {$wpdb->prefix}opb_clients c ON c.id=bk.client_id
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=bk.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id=bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p ON p.id=bs.pet_id
             WHERE $where_sql
             GROUP BY bk.id
             ORDER BY bk.booking_date DESC, bk.id DESC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows, $total, $page, $per_page));
    }

    public function cancel_booking( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_bookings WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Booking not found.', 404);
        if ( $row->status === 'Cancelled' ) return $this->error('already_cancelled', 'Booking is already cancelled.');

        $wpdb->update(
            "{$wpdb->prefix}opb_bookings",
            [ 'status' => 'Cancelled' ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
        return $this->success([ 'id' => $id, 'status' => 'Cancelled' ]);
    }

    public function restore_booking( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_bookings WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Booking not found.', 404);
        if ( $row->status === 'Active' ) return $this->error('already_active', 'Booking is already active.');

        $wpdb->update(
            "{$wpdb->prefix}opb_bookings",
            [ 'status' => 'Active' ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
        return $this->success([ 'id' => $id, 'status' => 'Active' ]);
    }

    // ── Inquiries ─────────────────────────────────────────────────────────────

    public function list_inquiries( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $view     = sanitize_text_field( $r->get_param('view') ?? 'active' );
        $search   = sanitize_text_field( $r->get_param('search') ?? '' );
        $page     = max(1,(int)($r->get_param('page')??1));
        $per_page = min(100,(int)($r->get_param('per_page')??50));
        $offset   = ($page-1)*$per_page;

        $where = ['1=1']; $args = [];

        if ( $view === 'active' ) {
            $where[] = "i.status NOT IN ('ARCHIVED','REJECTED')";
        } elseif ( $view === 'archived' ) {
            $where[] = "i.status IN ('ARCHIVED','REJECTED')";
        }

        if ( $search ) {
            $like    = '%' . esc_sql($search) . '%';
            $where[] = '(i.owner_name LIKE %s OR i.phone LIKE %s OR i.email LIKE %s OR i.pet_name LIKE %s)';
            $args    = array_merge($args, [$like, $like, $like, $like]);
        }

        $where_sql = implode(' AND ', $where);

        $total = (int)$wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_inquiries i WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT i.id, i.owner_name, i.phone, i.email, i.pet_name, i.pet_type,
                    i.status, i.created_at,
                    b.name AS branch_name
             FROM {$wpdb->prefix}opb_inquiries i
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             WHERE $where_sql
             ORDER BY i.created_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows, $total, $page, $per_page));
    }

    public function archive_inquiry( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_inquiries WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Inquiry not found.', 404);
        if ( $row->status === 'ARCHIVED' ) return $this->error('already_archived', 'Inquiry is already archived.');

        $wpdb->update(
            "{$wpdb->prefix}opb_inquiries",
            [ 'status' => 'ARCHIVED' ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
        return $this->success([ 'id' => $id, 'status' => 'ARCHIVED' ]);
    }

    public function restore_inquiry( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;
        $id  = (int)$r['id'];
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}opb_inquiries WHERE id=%d", $id
        ));
        if ( ! $row ) return $this->error('not_found', 'Inquiry not found.', 404);
        if ( ! in_array( $row->status, ['ARCHIVED','REJECTED'], true ) ) {
            return $this->error('not_archived', 'Inquiry is not archived.');
        }

        $wpdb->update(
            "{$wpdb->prefix}opb_inquiries",
            [ 'status' => 'NEW' ],
            [ 'id' => $id ],
            [ '%s' ], [ '%d' ]
        );
        return $this->success([ 'id' => $id, 'status' => 'NEW' ]);
    }
}
