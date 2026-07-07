<?php
/**
 * OPB_Client_Relationship_API
 *
 * REST endpoints for the client-facing relationship page (/my-pets/).
 * Uses Email OTP + custom session tokens — no WordPress user login required.
 *
 * Public (no auth):
 *   POST /opb/v1/client/auth/request-otp
 *   POST /opb/v1/client/auth/verify-otp
 *   POST /opb/v1/client/auth/logout
 *
 * Session-gated:
 *   GET  /opb/v1/client/me
 */
class OPB_Client_Relationship_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/client/auth/request-otp', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'request_otp' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/client/auth/verify-otp', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'verify_otp' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/client/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'logout' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/client/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_me' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/clients/(?P<id>\d+)/portal-preview', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'portal_preview' ],
            'permission_callback' => [ $this, 'permission_check' ],
            'args'                => [
                'id' => [ 'required' => true, 'type' => 'integer', 'minimum' => 1 ],
            ],
        ] );
    }

    // ── POST /client/auth/request-otp ────────────────────────────────────────

    public function request_otp( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $d     = $r->get_json_params() ?? [];
        $email = sanitize_email( $d['email'] ?? '' );

        if ( ! is_email( $email ) ) {
            return $this->error( 'invalid_email', 'Please enter a valid email address.' );
        }

        $ip = $this->client_ip();

        // Always return the same message to prevent email enumeration
        $client = OPB_Client_Auth::find_client_by_email( $email );

        if ( ! $client ) {
            OPB_Client_Auth::log( 0, 'otp_requested_unknown', $ip, $email );
            return $this->success( [
                'message' => 'If a registered account exists with that email, a verification code has been sent.',
            ] );
        }

        $otp = OPB_Client_Auth::generate_otp( (int) $client['id'], $email, $ip );
        if ( is_wp_error( $otp ) ) {
            return $otp;
        }

        OPB_Client_Auth::send_otp_email( $client, $otp );

        return $this->success( [
            'message' => 'If a registered account exists with that email, a verification code has been sent.',
        ] );
    }

    // ── POST /client/auth/verify-otp ─────────────────────────────────────────

    public function verify_otp( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $d     = $r->get_json_params() ?? [];
        $email = sanitize_email( $d['email'] ?? '' );
        $otp   = sanitize_text_field( $d['otp'] ?? '' );

        if ( ! is_email( $email ) ) {
            return $this->error( 'invalid_email', 'Please enter a valid email address.' );
        }
        if ( ! preg_match( '/^\d{6}$/', $otp ) ) {
            return $this->error( 'otp_invalid', 'Please enter a valid 6-digit verification code.', 401 );
        }

        $ip = $this->client_ip();
        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );

        $client_id = OPB_Client_Auth::verify_otp( $email, $otp, $ip );
        if ( is_wp_error( $client_id ) ) {
            return $client_id;
        }

        OPB_Client_Auth::create_session( $client_id, $ip, $ua );

        // Session is established via HttpOnly cookie only.
        // The plain token is intentionally not returned in the response body —
        // doing so would allow it to be used as a Bearer token from any browser,
        // breaking per-browser session isolation.
        return $this->success( [
            'message' => 'Signed in successfully.',
        ] );
    }

    // ── POST /client/auth/logout ──────────────────────────────────────────────

    public function logout( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $client_id = OPB_Client_Auth::get_session_client_id( $r );
        if ( $client_id ) {
            OPB_Client_Auth::log( $client_id, 'logout', $this->client_ip(), '' );
        }
        OPB_Client_Auth::invalidate_session( $r );
        return $this->success( [ 'message' => 'You have been signed out.' ] );
    }

    // ── GET /client/me ────────────────────────────────────────────────────────

    public function get_me( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        // Prevent any proxy, CDN, or server-side cache from storing or serving
        // this response to another request. Authentication is per-browser-session;
        // a cached 200 from an authenticated request must never be replayed to
        // an unauthenticated browser.
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );

        $client_id = OPB_Client_Auth::get_session_client_id( $r );
        if ( ! $client_id ) {
            return $this->error( 'unauthorized', 'Please sign in to continue.', 401 );
        }

        global $wpdb;

        // ── Client ────────────────────────────────────────────────────────────
        $client = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.id, c.name, c.email, c.phone, c.address,
                    c.local_guardian_name, c.local_guardian_contact,
                    c.onboarding_date,
                    b.name AS branch_name
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = c.home_branch_id
             WHERE c.id = %d AND c.status = 'active'
             LIMIT 1",
            $client_id
        ), ARRAY_A );

        if ( ! $client ) {
            OPB_Client_Auth::invalidate_session( $r );
            return $this->error( 'not_found', 'Account not found.', 404 );
        }

        // ── Pets with documents ───────────────────────────────────────────────
        $pets_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, pet_type, breed, gender, breed_size,
                    birthday, vaccination_status,
                    anti_rabies_date, dhppil_date, kennel_cough_date,
                    ongoing_medication, medication_detail,
                    dietary_preference, vet_name, vet_contact
             FROM {$wpdb->prefix}opb_pets
             WHERE client_id = %d AND is_active = 1
             ORDER BY name ASC",
            $client_id
        ), ARRAY_A );

        $pets = [];
        foreach ( $pets_raw as $pet ) {
            // Age from birthday
            $pet['age'] = '';
            if ( ! empty( $pet['birthday'] ) ) {
                try {
                    $diff = ( new DateTime() )->diff( new DateTime( $pet['birthday'] ) );
                    if ( $diff->y > 0 ) {
                        $pet['age'] = $diff->y . ' yr' . ( $diff->y !== 1 ? 's' : '' );
                        if ( $diff->m > 0 ) {
                            $pet['age'] .= ', ' . $diff->m . ' mo';
                        }
                    } elseif ( $diff->m > 0 ) {
                        $pet['age'] = $diff->m . ' month' . ( $diff->m !== 1 ? 's' : '' );
                    } else {
                        $pet['age'] = $diff->d . ' day' . ( $diff->d !== 1 ? 's' : '' );
                    }
                } catch ( \Exception $e ) {}
            }

            // Documents
            $docs = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, doc_type, label, file_url, file_mime
                 FROM {$wpdb->prefix}opb_pet_documents
                 WHERE pet_id = %d
                 ORDER BY doc_type ASC, seq_number ASC",
                (int) $pet['id']
            ), ARRAY_A );

            $pet['photo_url'] = '';
            $pet['documents'] = [];

            foreach ( $docs as $doc ) {
                if ( $doc['doc_type'] === 'photo' && ! $pet['photo_url'] ) {
                    $pet['photo_url'] = $doc['file_url'];
                } else {
                    $pet['documents'][] = $doc;
                }
            }

            $pets[] = $pet;
        }

        // ── Bookings (upcoming + past) ────────────────────────────────────────
        $bookings_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT bk.id, bk.booking_date, bk.payment_status, bk.service_types,
                    b.name AS branch_name,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS pet_names,
                    MIN(bs.check_in_date)  AS check_in_date,
                    MAX(bs.check_out_date) AS check_out_date,
                    GROUP_CONCAT(DISTINCT bs.status ORDER BY bs.id SEPARATOR ', ') AS stay_statuses
             FROM {$wpdb->prefix}opb_bookings bk
             LEFT JOIN {$wpdb->prefix}opb_branches b      ON b.id  = bk.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id = bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p           ON p.id  = bs.pet_id
             WHERE bk.client_id = %d
             GROUP BY bk.id
             ORDER BY bk.booking_date DESC
             LIMIT 60",
            $client_id
        ), ARRAY_A );

        $today    = gmdate( 'Y-m-d' );
        $upcoming = [];
        $past     = [];

        foreach ( $bookings_raw as $bk ) {
            $out = $bk['check_out_date'] ?? $bk['booking_date'];
            if ( $out >= $today ) {
                $upcoming[] = $bk;
            } else {
                $past[] = $bk;
            }
        }

        // ── Invoices ──────────────────────────────────────────────────────────
        $invoices = $wpdb->get_results( $wpdb->prepare(
            "SELECT inv.id, inv.invoice_date, inv.payment_status,
                    inv.revenue, inv.paid, inv.due, inv.doc_token,
                    bk.booking_date
             FROM {$wpdb->prefix}opb_invoices inv
             JOIN  {$wpdb->prefix}opb_bookings bk ON bk.id = inv.booking_id
             WHERE bk.client_id = %d
             ORDER BY inv.invoice_date DESC
             LIMIT 30",
            $client_id
        ), ARRAY_A );

        foreach ( $invoices as &$inv ) {
            $inv['pdf_url'] = $inv['doc_token']
                ? home_url( '/opb-invoice/' . $inv['doc_token'] . '/' )
                : null;
        }
        unset( $inv );

        // ── Support (from Customizations) ─────────────────────────────────────
        $my_pets_url = home_url( '/my-pets/' );
        $support_ctx = [
            'CLIENT_NAME'   => $client['name'] ?? '',
            'CLIENT_EMAIL'  => $client['email'] ?? '',
            'CLIENT_PHONE'  => $client['phone'] ?? '',
            'FACILITY_NAME' => OPB_Customizations::facility_name(),
            'SUPPORT_PHONE' => OPB_Customizations::get( 'facility_phone' ),
            'SUPPORT_EMAIL' => OPB_Customizations::get( 'facility_email' ),
            'MY_PETS_URL'   => $my_pets_url,
        ];
        $support = [
            'facility_name'    => OPB_Customizations::facility_name(),
            'phone'            => OPB_Customizations::get( 'facility_phone' ),
            'email'            => OPB_Customizations::get( 'facility_email' ),
            'email_subject'    => OPB_Customizations::render( 'client_support_email_subject', $support_ctx ),
            'email_body'       => OPB_Customizations::render( 'client_support_email_body', $support_ctx ),
            'whatsapp_message' => OPB_Customizations::render( 'client_support_whatsapp_message', $support_ctx ),
        ];

        OPB_Client_Auth::log( $client_id, 'page_accessed', $this->client_ip(), 'me' );

        return $this->success( [
            'client'   => $client,
            'pets'     => $pets,
            'bookings' => [
                'upcoming' => array_values( $upcoming ),
                'past'     => array_values( $past ),
            ],
            'invoices' => $invoices,
            'support'  => $support,
        ] );
    }

    // ── GET /clients/{id}/portal-preview ─────────────────────────────────────

    public function portal_preview( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $client_id = (int) $r->get_param( 'id' );

        global $wpdb;

        // ── Client ────────────────────────────────────────────────────────────
        $client = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.id, c.name, c.email, c.phone, c.address,
                    c.local_guardian_name, c.local_guardian_contact,
                    c.onboarding_date,
                    b.name AS branch_name
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = c.home_branch_id
             WHERE c.id = %d
             LIMIT 1",
            $client_id
        ), ARRAY_A );

        if ( ! $client ) {
            return $this->error( 'not_found', 'Client not found.', 404 );
        }

        // ── Pets with documents ───────────────────────────────────────────────
        $pets_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, pet_type, breed, gender, breed_size,
                    birthday, vaccination_status,
                    anti_rabies_date, dhppil_date, kennel_cough_date,
                    ongoing_medication, medication_detail,
                    dietary_preference, vet_name, vet_contact
             FROM {$wpdb->prefix}opb_pets
             WHERE client_id = %d AND is_active = 1
             ORDER BY name ASC",
            $client_id
        ), ARRAY_A );

        $pets = [];
        foreach ( $pets_raw as $pet ) {
            $pet['age'] = '';
            if ( ! empty( $pet['birthday'] ) ) {
                try {
                    $diff = ( new DateTime() )->diff( new DateTime( $pet['birthday'] ) );
                    if ( $diff->y > 0 ) {
                        $pet['age'] = $diff->y . ' yr' . ( $diff->y !== 1 ? 's' : '' );
                        if ( $diff->m > 0 ) { $pet['age'] .= ', ' . $diff->m . ' mo'; }
                    } elseif ( $diff->m > 0 ) {
                        $pet['age'] = $diff->m . ' month' . ( $diff->m !== 1 ? 's' : '' );
                    } else {
                        $pet['age'] = $diff->d . ' day' . ( $diff->d !== 1 ? 's' : '' );
                    }
                } catch ( \Exception $e ) {}
            }

            $docs = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, doc_type, label, file_url, file_mime
                 FROM {$wpdb->prefix}opb_pet_documents
                 WHERE pet_id = %d
                 ORDER BY doc_type ASC, seq_number ASC",
                (int) $pet['id']
            ), ARRAY_A );

            $pet['photo_url'] = '';
            $pet['documents'] = [];
            foreach ( $docs as $doc ) {
                if ( $doc['doc_type'] === 'photo' && ! $pet['photo_url'] ) {
                    $pet['photo_url'] = $doc['file_url'];
                } else {
                    $pet['documents'][] = $doc;
                }
            }

            $pets[] = $pet;
        }

        // ── Bookings ──────────────────────────────────────────────────────────
        $bookings_raw = $wpdb->get_results( $wpdb->prepare(
            "SELECT bk.id, bk.booking_date, bk.payment_status, bk.service_types,
                    b.name AS branch_name,
                    GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS pet_names,
                    MIN(bs.check_in_date)  AS check_in_date,
                    MAX(bs.check_out_date) AS check_out_date,
                    GROUP_CONCAT(DISTINCT bs.status ORDER BY bs.id SEPARATOR ', ') AS stay_statuses
             FROM {$wpdb->prefix}opb_bookings bk
             LEFT JOIN {$wpdb->prefix}opb_branches b      ON b.id  = bk.branch_id
             LEFT JOIN {$wpdb->prefix}opb_booking_stays bs ON bs.booking_id = bk.id
             LEFT JOIN {$wpdb->prefix}opb_pets p           ON p.id  = bs.pet_id
             WHERE bk.client_id = %d
             GROUP BY bk.id
             ORDER BY bk.booking_date DESC
             LIMIT 60",
            $client_id
        ), ARRAY_A );

        $today    = gmdate( 'Y-m-d' );
        $upcoming = [];
        $past     = [];
        foreach ( $bookings_raw as $bk ) {
            $out = $bk['check_out_date'] ?? $bk['booking_date'];
            if ( $out >= $today ) { $upcoming[] = $bk; } else { $past[] = $bk; }
        }

        // ── Invoices ──────────────────────────────────────────────────────────
        $invoices = $wpdb->get_results( $wpdb->prepare(
            "SELECT inv.id, inv.invoice_date, inv.payment_status,
                    inv.revenue, inv.paid, inv.due, inv.doc_token,
                    bk.booking_date
             FROM {$wpdb->prefix}opb_invoices inv
             JOIN  {$wpdb->prefix}opb_bookings bk ON bk.id = inv.booking_id
             WHERE bk.client_id = %d
             ORDER BY inv.invoice_date DESC
             LIMIT 30",
            $client_id
        ), ARRAY_A );

        foreach ( $invoices as &$inv ) {
            $inv['pdf_url'] = $inv['doc_token']
                ? home_url( '/opb-invoice/' . $inv['doc_token'] . '/' )
                : null;
        }
        unset( $inv );

        // ── Support ───────────────────────────────────────────────────────────
        $my_pets_url = home_url( '/my-pets/' );
        $support_ctx = [
            'CLIENT_NAME'   => $client['name'] ?? '',
            'CLIENT_EMAIL'  => $client['email'] ?? '',
            'CLIENT_PHONE'  => $client['phone'] ?? '',
            'FACILITY_NAME' => OPB_Customizations::facility_name(),
            'SUPPORT_PHONE' => OPB_Customizations::get( 'facility_phone' ),
            'SUPPORT_EMAIL' => OPB_Customizations::get( 'facility_email' ),
            'MY_PETS_URL'   => $my_pets_url,
        ];
        $support = [
            'facility_name'    => OPB_Customizations::facility_name(),
            'phone'            => OPB_Customizations::get( 'facility_phone' ),
            'email'            => OPB_Customizations::get( 'facility_email' ),
            'email_subject'    => OPB_Customizations::render( 'client_support_email_subject', $support_ctx ),
            'email_body'       => OPB_Customizations::render( 'client_support_email_body', $support_ctx ),
            'whatsapp_message' => OPB_Customizations::render( 'client_support_whatsapp_message', $support_ctx ),
        ];

        return $this->success( [
            'client'   => $client,
            'pets'     => $pets,
            'bookings' => [
                'upcoming' => array_values( $upcoming ),
                'past'     => array_values( $past ),
            ],
            'invoices' => $invoices,
            'support'  => $support,
        ] );
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function client_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', wp_unslash( $_SERVER[ $key ] ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
