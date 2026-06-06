<?php
/**
 * OPB_Customizations_API
 *
 * REST endpoints for the Customization subsystem.
 *
 * GET  /settings/customizations            → all settings (admin + manager view)
 * PUT  /settings/customizations/{key}      → update one setting (admin only)
 * GET  /settings/customizations/export     → JSON export (admin only)
 * POST /settings/customizations/preview    → render template with sample data (admin only)
 *
 * NOTE: export and preview routes are registered before the parameterised {key} route
 * so WordPress tries those specific paths first.
 */
class OPB_Customizations_API extends OPB_REST_Base {

    public function register_routes(): void {
        // ── List all ──────────────────────────────────────────────────────────
        register_rest_route( $this->namespace, '/settings/customizations', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_all' ],
                'permission_callback' => [ $this, 'permission_view' ],
            ],
        ] );

        // ── Export (registered before parameterised route) ────────────────────
        register_rest_route( $this->namespace, '/settings/customizations/export', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'export' ],
                'permission_callback' => [ $this, 'permission_edit' ],
            ],
        ] );

        // ── Preview (registered before parameterised route) ───────────────────
        register_rest_route( $this->namespace, '/settings/customizations/preview', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'preview' ],
                'permission_callback' => [ $this, 'permission_edit' ],
            ],
        ] );

        // ── Media upload (registered before parameterised route) ─────────────
        register_rest_route( $this->namespace, '/settings/customizations/upload-media', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'upload_media' ],
                'permission_callback' => [ $this, 'permission_edit' ],
            ],
        ] );

        // ── Update one key ────────────────────────────────────────────────────
        register_rest_route( $this->namespace, '/settings/customizations/(?P<key>[a-z_]+)', [
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_one' ],
                'permission_callback' => [ $this, 'permission_edit' ],
            ],
        ] );
    }

    // ── Permission helpers ────────────────────────────────────────────────────

    /**
     * View: any authenticated OPB user (UI enforces read-only for non-admins).
     */
    public function permission_view( WP_REST_Request $r ): bool|WP_Error {
        return $this->permission_check( $r );
    }

    /**
     * Edit: opb_manage_settings capability or WP administrator.
     */
    public function permission_edit( WP_REST_Request $r ): bool|WP_Error {
        return $this->permission_manage( 'opb_manage_settings', $r );
    }

    // ── Handlers ──────────────────────────────────────────────────────────────

    public function get_all( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_view( $r );
        if ( is_wp_error( $check ) ) return $check;

        $items = array_values( OPB_Customizations::get_all() );

        // For media-type settings, resolve the attachment ID to a public URL
        // so the frontend can render a live preview without an extra round-trip.
        foreach ( $items as &$item ) {
            if ( ( $item['type'] ?? '' ) === 'media' ) {
                $item['media_url'] = OPB_Customizations::get_media_url( $item['key'] );
            }
        }
        unset( $item );

        return $this->success( $items );
    }

    public function update_one( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_edit( $r );
        if ( is_wp_error( $check ) ) return $check;

        $key      = sanitize_key( $r['key'] );
        $d        = $r->get_json_params();
        $value    = isset( $d['value'] ) ? (string) $d['value'] : '';
        $registry = OPB_Customizations::REGISTRY;

        if ( ! isset( $registry[ $key ] ) ) {
            return $this->error( 'not_found', 'Unknown setting key: ' . $key, 404 );
        }

        // Basic length guard for single-line text fields
        if ( $registry[ $key ]['type'] === 'text' && mb_strlen( $value ) > 500 ) {
            return $this->error( 'invalid', 'Value exceeds the maximum length for a text field (500 chars).' );
        }

        OPB_Customizations::set( $key, $value );

        $all = OPB_Customizations::get_all();
        return $this->success( $all[ $key ] );
    }

    public function export( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_edit( $r );
        if ( is_wp_error( $check ) ) return $check;

        $all     = OPB_Customizations::get_all();
        $export  = [];
        foreach ( $all as $key => $meta ) {
            $export[] = [
                'setting_key'   => $key,
                'setting_value' => $meta['value'],
                'category'      => $meta['category'],
                'is_default'    => $meta['is_default'],
                'updated_at'    => $meta['updated_at'],
                'updated_by'    => $meta['updated_by'],
            ];
        }

        return $this->success( [
            'exported_at'    => gmdate( 'c' ),
            'plugin_version' => OPB_VERSION,
            'settings'       => $export,
        ] );
    }

    public function upload_media( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_edit( $r );
        if ( is_wp_error( $check ) ) return $check;

        $files = $r->get_file_params();
        if ( empty( $files['file'] ) ) {
            return $this->error( 'invalid', 'No file uploaded. Send a multipart/form-data request with field name "file".' );
        }

        $key = sanitize_key( (string) ( $r->get_param( 'key' ) ?? '' ) );
        if ( $key ) {
            if ( ! isset( OPB_Customizations::REGISTRY[ $key ] ) ) {
                return $this->error( 'not_found', 'Unknown setting key: ' . $key, 404 );
            }
            if ( OPB_Customizations::REGISTRY[ $key ]['type'] !== 'media' ) {
                return $this->error( 'invalid', 'Setting "' . $key . '" is not a media field.' );
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return $this->error( 'upload_failed', $attachment_id->get_error_message() );
        }

        if ( $key ) {
            OPB_Customizations::set( $key, (string) $attachment_id );
        }

        return $this->success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
            'key'           => $key ?: null,
        ], 201 );
    }

    public function preview( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_edit( $r );
        if ( is_wp_error( $check ) ) return $check;

        $d   = $r->get_json_params();
        $key = sanitize_key( $d['key'] ?? '' );

        if ( empty( $key ) ) {
            return $this->error( 'invalid', 'key is required.' );
        }
        if ( ! isset( OPB_Customizations::REGISTRY[ $key ] ) ) {
            return $this->error( 'not_found', 'Unknown setting key: ' . $key, 404 );
        }

        $template = OPB_Customizations::get( $key );
        $context  = OPB_Customizations::sample_context();
        $rendered = OPB_Customizations::render_string( $template, $context );
        $invalid  = OPB_Customizations::validate_placeholders( $template );

        return $this->success( [
            'key'      => $key,
            'template' => $template,
            'rendered' => $rendered,
            'context'  => $context,
            'warnings' => array_map(
                fn( $p ) => "Unknown placeholder: {{{$p}}} — did you mean one of: " . implode( ', ', array_map( fn( $v ) => '{{' . $v . '}}', OPB_Customizations::VALID_PLACEHOLDERS ) ) . '?',
                $invalid
            ),
        ] );
    }
}
