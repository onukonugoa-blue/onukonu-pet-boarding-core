<?php
/**
 * OPB_Invoice_Delivery_API
 *
 * REST routes for the invoice document engine.
 *
 * POST /invoices/{id}/document/generate  — generate (or regenerate) PDF document
 * GET  /invoices/{id}/document            — get stored document metadata (incl. pdf_url)
 * POST /invoices/{id}/send-email          — send invoice via email (PDF attached)
 * GET  /invoices/{id}/whatsapp-link       — get WhatsApp sharing URL + message
 * GET  /invoices/{id}/audit               — get audit trail for this invoice
 */
class OPB_Invoice_Delivery_API extends OPB_REST_Base {

    public function register_routes(): void {

        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/document/generate', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'generate_document' ],
                'permission_callback' => fn( $r ) => $this->permission_manage( 'opb_manage_invoices', $r ),
            ],
        ] );

        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/document', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_document' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/send-email', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'send_email' ],
                'permission_callback' => fn( $r ) => $this->permission_manage( 'opb_manage_invoices', $r ),
            ],
        ] );

        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/whatsapp-link', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'whatsapp_link' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
        ] );

        register_rest_route( $this->namespace, '/invoices/(?P<id>\d+)/audit', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_audit' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
        ] );
    }

    // ── Handlers ───────────────────────────────────────────────────────────────

    public function generate_document( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $id = (int) $r['id'];
        try {
            $result = OPB_Invoice_Document::generate( $id );
            return $this->success( $result, 200 );
        } catch ( \RuntimeException $e ) {
            $code = str_contains( $e->getMessage(), 'not found' ) ? 404 : 500;
            return $this->error( 'document_generate_failed', $e->getMessage(), $code );
        }
    }

    public function get_document( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check( $r );
        if ( is_wp_error( $check ) ) return $check;

        $id   = (int) $r['id'];
        $info = OPB_Invoice_Document::get_info( $id );

        return $this->success( $info );
    }

    public function send_email( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $id = (int) $r['id'];
        $to = sanitize_email( (string) ( $r->get_param( 'to' ) ?? '' ) );

        try {
            $result = OPB_Invoice_Document::send_email( $id, $to );
            return $this->success( $result );
        } catch ( \RuntimeException $e ) {
            $code = str_contains( $e->getMessage(), 'not found' ) ? 404 : 422;
            return $this->error( 'email_send_failed', $e->getMessage(), $code );
        }
    }

    public function whatsapp_link( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check( $r );
        if ( is_wp_error( $check ) ) return $check;

        $id = (int) $r['id'];
        try {
            $result = OPB_Invoice_Document::get_whatsapp_link( $id );
            return $this->success( $result );
        } catch ( \RuntimeException $e ) {
            $code = str_contains( $e->getMessage(), 'not found' ) ? 404 : 500;
            return $this->error( 'whatsapp_link_failed', $e->getMessage(), $code );
        }
    }

    public function get_audit( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $check = $this->permission_check( $r );
        if ( is_wp_error( $check ) ) return $check;

        $id = (int) $r['id'];
        return $this->success( OPB_Invoice_Document::get_audit( $id ) );
    }
}
