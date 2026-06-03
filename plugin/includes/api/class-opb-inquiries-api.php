<?php
/**
 * Staff Inquiry REST API
 *
 * All routes require WordPress authentication + OPB role.
 *
 * GET    /opb/v1/inquiries
 * GET    /opb/v1/inquiries/{id}
 * PUT    /opb/v1/inquiries/{id}
 * POST   /opb/v1/inquiries/{id}/notes
 * POST   /opb/v1/inquiries/{id}/send-onboarding
 * POST   /opb/v1/inquiries/{id}/reject
 * POST   /opb/v1/inquiries/{id}/archive
 * GET    /opb/v1/inquiries/{id}/duplicate-check
 * POST   /opb/v1/inquiries/{id}/convert
 */
class OPB_Inquiries_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/inquiries', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_items' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'get_item'    ], 'permission_callback' => [ $this, 'permission_check' ] ],
            [ 'methods' => 'PUT', 'callback' => [ $this, 'update_item' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/notes', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'add_note' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/send-onboarding', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'send_onboarding' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/resend-onboarding', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'resend_onboarding' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/reject', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'reject' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/archive', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'archive' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/duplicate-check', [
            [ 'methods' => 'GET', 'callback' => [ $this, 'duplicate_check' ], 'permission_callback' => [ $this, 'permission_check' ] ],
        ] );

        register_rest_route( $ns, '/inquiries/(?P<id>\d+)/convert', [
            [ 'methods' => 'POST', 'callback' => [ $this, 'convert' ], 'permission_callback' => fn($r)=>$this->permission_manage('opb_manage_clients',$r) ],
        ] );
    }

    // ── List ───────────────────────────────────────────────────────────────────

    public function get_items( $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $branch_id = $this->branch_filter( (int)($r->get_param('branch_id')??0) );
        $status    = sanitize_text_field( $r->get_param('status') ?? '' );
        $search    = sanitize_text_field( $r->get_param('search') ?? '' );
        $page      = max(1,(int)($r->get_param('page')??1));
        $per_page  = min(100,(int)($r->get_param('per_page')??50));
        $offset    = ($page-1)*$per_page;

        $where = ['1=1'];
        $args  = [];

        if ( $branch_id ) {
            $where[] = 'i.branch_id = %d';
            $args[]  = $branch_id;
        }
        if ( $status ) {
            $where[] = 'i.status = %s';
            $args[]  = $status;
        }
        if ( $search ) {
            $like    = '%'.esc_sql($search).'%';
            $where[] = '(i.owner_name LIKE %s OR i.phone LIKE %s OR i.email LIKE %s OR i.pet_name LIKE %s)';
            $args    = array_merge($args,[$like,$like,$like,$like]);
        }

        $where_sql = implode(' AND ',$where);

        $total = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_inquiries i WHERE $where_sql",
            ...$args
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.token, i.owner_name, i.phone, i.email, i.pet_name, i.pet_type,
                    i.desired_check_in, i.desired_check_out, i.status, i.existing_client_id,
                    i.onboarding_sent_at, i.delivery_method, i.converted_client_id,
                    i.ip_address, i.source, i.created_at, i.updated_at,
                    b.name AS branch_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_inquiry_notes n WHERE n.inquiry_id=i.id) AS note_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_onboarding_documents d WHERE d.inquiry_id=i.id) AS doc_count
             FROM {$wpdb->prefix}opb_inquiries i
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             WHERE $where_sql
             ORDER BY i.created_at DESC
             LIMIT %d OFFSET %d",
            ...[...$args,$per_page,$offset]
        ), ARRAY_A);

        return $this->success($this->paginate($rows,$total,$page,$per_page));
    }

    // ── Detail ─────────────────────────────────────────────────────────────────

    public function get_item( $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id = (int)$r['id'];
        $inquiry = $wpdb->get_row($wpdb->prepare(
            "SELECT i.*, b.name AS branch_name
             FROM {$wpdb->prefix}opb_inquiries i
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=i.branch_id
             WHERE i.id=%d",
            $id
        ), ARRAY_A);

        if (!$inquiry) return $this->error('not_found','Inquiry not found.',404);

        $inquiry['onboarding_url'] = OPB_Onboarding_Handler::onboarding_url( $inquiry['token'] );

        $notes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_inquiry_notes WHERE inquiry_id=%d ORDER BY created_at ASC",
            $id
        ), ARRAY_A);

        $ob_client = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_clients WHERE inquiry_id=%d",
            $id
        ), ARRAY_A);

        $ob_pets = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_pets WHERE inquiry_id=%d ORDER BY id ASC",
            $id
        ), ARRAY_A);

        $ob_docs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, onboarding_pet_id, doc_type, label, file_url, file_mime, uploaded_at
             FROM {$wpdb->prefix}opb_onboarding_documents WHERE inquiry_id=%d",
            $id
        ), ARRAY_A);

        // Existing client detail (if flagged)
        $existing_client = null;
        if ($inquiry['existing_client_id']) {
            $existing_client = $wpdb->get_row($wpdb->prepare(
                "SELECT c.id, c.name, c.phone, c.email, b.name AS branch_name,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets p WHERE p.client_id=c.id AND p.is_active=1) AS pet_count,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings bk WHERE bk.client_id=c.id) AS booking_count
                 FROM {$wpdb->prefix}opb_clients c
                 LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id=c.home_branch_id
                 WHERE c.id=%d",
                $inquiry['existing_client_id']
            ), ARRAY_A);
        }

        return $this->success([
            'inquiry'         => $inquiry,
            'notes'           => $notes,
            'onboarding_client' => $ob_client,
            'onboarding_pets' => $ob_pets,
            'documents'       => $ob_docs,
            'existing_client' => $existing_client,
        ]);
    }

    // ── Update Status ──────────────────────────────────────────────────────────

    public function update_item( $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id = (int)$r['id'];
        $d  = $r->get_json_params() ?? [];

        $allowed_statuses = ['NEW','CONTACTED','ONBOARDING_SENT','ONBOARDING_COMPLETED','READY_FOR_REVIEW'];
        $status = sanitize_text_field($d['status'] ?? '');

        if ($status && !in_array($status, $allowed_statuses, true)) {
            return $this->error('invalid','Invalid status value.');
        }

        $update = [];
        if ($status) $update['status'] = $status;

        if (empty($update)) return $this->error('invalid','Nothing to update.');

        $wpdb->update("{$wpdb->prefix}opb_inquiries", $update, ['id'=>$id]);
        return $this->get_item($r);
    }

    // ── Add Note ───────────────────────────────────────────────────────────────

    public function add_note( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id   = (int)$r['id'];
        $d    = $r->get_json_params() ?? [];
        $note = sanitize_textarea_field($d['note'] ?? '');

        if (!$note) return $this->error('invalid','note is required.');

        $user = wp_get_current_user();
        $wpdb->insert("{$wpdb->prefix}opb_inquiry_notes",[
            'inquiry_id'      => $id,
            'note'            => $note,
            'created_by'      => $user->ID,
            'created_by_name' => $user->display_name,
        ]);

        return $this->success([
            'id'              => (int)$wpdb->insert_id,
            'inquiry_id'      => $id,
            'note'            => $note,
            'created_by'      => $user->ID,
            'created_by_name' => $user->display_name,
            'created_at'      => current_time('mysql'),
        ], 201);
    }

    // ── Send Onboarding ────────────────────────────────────────────────────────

    public function send_onboarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id      = (int)$r['id'];
        $d       = $r->get_json_params() ?? [];
        $method  = sanitize_text_field($d['delivery_method'] ?? 'MANUAL');

        if (!in_array($method, ['EMAIL','WHATSAPP','MANUAL'], true)) {
            $method = 'MANUAL';
        }

        $inquiry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_inquiries WHERE id=%d", $id
        ), ARRAY_A);

        if (!$inquiry) return $this->error('not_found','Inquiry not found.',404);

        $user = wp_get_current_user();

        $wpdb->update("{$wpdb->prefix}opb_inquiries",[
            'status'             => 'ONBOARDING_SENT',
            'onboarding_sent_at' => current_time('mysql'),
            'onboarding_sent_by' => $user->ID,
            'delivery_method'    => $method,
        ],['id'=>$id]);

        $onboarding_url = OPB_Onboarding_Handler::onboarding_url($inquiry['token']);
        $wa_url         = null;

        if ($method === 'WHATSAPP') {
            $wa_url = OPB_Onboarding_Handler::whatsapp_url(
                $inquiry['phone'],
                $inquiry['owner_name'],
                $inquiry['token']
            );
        }

        // Always email the link to the customer if they have an address on file,
        // regardless of the chosen delivery method.
        OPB_Notifications::notify_customer_onboarding_link( $inquiry, $onboarding_url );

        return $this->success([
            'onboarding_url'   => $onboarding_url,
            'whatsapp_url'     => $wa_url,
            'delivery_method'  => $method,
            'sent_at'          => current_time('mysql'),
        ]);
    }

    // ── Resend Onboarding Link ─────────────────────────────────────────────────

    public function resend_onboarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id      = (int)$r['id'];
        $inquiry = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_inquiries WHERE id=%d", $id
        ), ARRAY_A);

        if (!$inquiry) return $this->error('not_found','Inquiry not found.',404);

        if (in_array($inquiry['status'], ['CONVERTED','REJECTED','ARCHIVED'], true)) {
            return $this->error('closed','Cannot resend — this inquiry is no longer active.',410);
        }

        if (empty($inquiry['token'])) {
            return $this->error('no_token','No onboarding token found for this inquiry.',400);
        }

        // Refresh sent timestamp without changing status
        $user = wp_get_current_user();
        $wpdb->update("{$wpdb->prefix}opb_inquiries",[
            'onboarding_sent_at' => current_time('mysql'),
            'onboarding_sent_by' => $user->ID,
        ],['id'=>$id]);

        $onboarding_url = OPB_Onboarding_Handler::onboarding_url($inquiry['token']);

        // Re-fire the customer email (skips silently if no email on file)
        OPB_Notifications::notify_customer_onboarding_link($inquiry, $onboarding_url);

        // Build WhatsApp URL so the frontend can open it if needed
        $wa_url = OPB_Onboarding_Handler::whatsapp_url(
            $inquiry['phone'],
            $inquiry['owner_name'],
            $inquiry['token']
        );

        return $this->success([
            'onboarding_url'  => $onboarding_url,
            'whatsapp_url'    => $wa_url,
            'resent_at'       => current_time('mysql'),
        ]);
    }

    // ── Reject ─────────────────────────────────────────────────────────────────

    public function reject( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id = (int)$r['id'];
        $d  = $r->get_json_params() ?? [];

        $wpdb->update("{$wpdb->prefix}opb_inquiries",['status'=>'REJECTED'],['id'=>$id]);

        if (!empty($d['reason'])) {
            $user = wp_get_current_user();
            $wpdb->insert("{$wpdb->prefix}opb_inquiry_notes",[
                'inquiry_id'      => $id,
                'note'            => '[Rejected] '.sanitize_textarea_field($d['reason']),
                'created_by'      => $user->ID,
                'created_by_name' => $user->display_name,
            ]);
        }

        return $this->success(['status'=>'REJECTED']);
    }

    // ── Archive ────────────────────────────────────────────────────────────────

    public function archive( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id = (int)$r['id'];
        $wpdb->update("{$wpdb->prefix}opb_inquiries",['status'=>'ARCHIVED'],['id'=>$id]);
        return $this->success(['status'=>'ARCHIVED']);
    }

    // ── Duplicate Check ────────────────────────────────────────────────────────

    public function duplicate_check( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check($r); if(is_wp_error($check)) return $check;
        global $wpdb;

        $id      = (int)$r['id'];
        $inquiry = $wpdb->get_row($wpdb->prepare(
            "SELECT phone, email FROM {$wpdb->prefix}opb_inquiries WHERE id=%d", $id
        ), ARRAY_A);

        if (!$inquiry) return $this->error('not_found','Inquiry not found.',404);

        $match = OPB_Onboarding_Handler::find_duplicate_client($inquiry['phone'], $inquiry['email']);

        return $this->success([
            'duplicate_found' => (bool)$match,
            'client'          => $match,
        ]);
    }

    // ── Convert to Client ──────────────────────────────────────────────────────

    public function convert( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_manage('opb_manage_clients',$r); if(is_wp_error($check)) return $check;

        $id   = (int)$r['id'];
        $d    = $r->get_json_params() ?? [];
        $user = wp_get_current_user();

        // Branch: from request param, else staff branch, else 0 (admin must pick)
        $branch_id = (int)($d['branch_id'] ?? $this->branch_filter(0));
        if (!$branch_id) {
            return $this->error('invalid','branch_id is required for conversion.');
        }

        try {
            $result = OPB_Onboarding_Handler::convert($id, $branch_id, $user->ID);
        } catch (RuntimeException $e) {
            return $this->error('conversion_failed', $e->getMessage(), 422);
        }

        return $this->success(array_merge($result,[
            'message' => 'Inquiry successfully converted to client.',
        ]), 201);
    }
}
