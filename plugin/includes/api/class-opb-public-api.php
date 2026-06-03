<?php
/**
 * Public-facing REST endpoints — no authentication required.
 *
 * Routes:
 *   POST /opb/v1/public/inquiries
 *   GET  /opb/v1/public/onboarding/{token}
 *   POST /opb/v1/public/onboarding/{token}/submit
 *   POST /opb/v1/public/onboarding/{token}/documents
 *   POST /opb/v1/public/onboarding/{token}/accept-terms
 */
class OPB_Public_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/public/inquiries', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_inquiry' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/public/onboarding/(?P<token>[a-f0-9]{64})', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_onboarding' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/public/onboarding/(?P<token>[a-f0-9]{64})/submit', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'submit_onboarding' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/public/onboarding/(?P<token>[a-f0-9]{64})/documents', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'upload_document' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/public/onboarding/(?P<token>[a-f0-9]{64})/accept-terms', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'accept_terms' ],
            'permission_callback' => '__return_true',
        ] );
    }

    // ── Submit Inquiry ─────────────────────────────────────────────────────────

    public function submit_inquiry( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $d = $r->get_json_params() ?? [];

        $name  = sanitize_text_field( $d['owner_name'] ?? '' );
        $phone = sanitize_text_field( $d['phone'] ?? '' );

        if ( ! $name || ! $phone ) {
            return $this->error( 'invalid', 'owner_name and phone are required.' );
        }

        $token = OPB_Onboarding_Handler::generate_token();

        // Detect existing client by phone
        $existing_client = OPB_Onboarding_Handler::find_duplicate_client( $phone, sanitize_email( $d['email'] ?? '' ) );

        $wpdb->insert( "{$wpdb->prefix}opb_inquiries", [
            'token'              => $token,
            'branch_id'          => ! empty( $d['branch_id'] ) ? (int) $d['branch_id'] : null,
            'owner_name'         => $name,
            'phone'              => $phone,
            'email'              => sanitize_email( $d['email'] ?? '' ),
            'pet_name'           => sanitize_text_field( $d['pet_name'] ?? '' ),
            'pet_type'           => sanitize_text_field( $d['pet_type'] ?? '' ),
            'desired_check_in'   => $this->safe_date( $d['desired_check_in'] ?? '' ),
            'desired_check_out'  => $this->safe_date( $d['desired_check_out'] ?? '' ),
            'message'            => sanitize_textarea_field( $d['message'] ?? '' ),
            'status'             => 'NEW',
            'existing_client_id' => $existing_client ? (int) $existing_client['id'] : null,
            'ip_address'         => $this->get_ip(),
            'source'             => sanitize_text_field( $d['source'] ?? 'web_form' ),
        ] );

        if ( ! $wpdb->insert_id ) {
            return $this->error( 'db_error', 'Could not save inquiry.', 500 );
        }

        $response = [ 'message' => 'Thank you! Your inquiry has been received. Our team will be in touch shortly.' ];

        if ( $existing_client ) {
            $response['existing_client'] = true;
        }

        return $this->success( $response, 201 );
    }

    // ── Get Onboarding Form Data ───────────────────────────────────────────────

    public function get_onboarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $token   = $r['token'];
        $inquiry = $this->get_inquiry_by_token( $token );
        if ( is_wp_error( $inquiry ) ) return $inquiry;

        $ob_client = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_clients WHERE inquiry_id = %d",
            $inquiry['id']
        ), ARRAY_A );

        $ob_pets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_pets WHERE inquiry_id = %d ORDER BY id ASC",
            $inquiry['id']
        ), ARRAY_A );

        $ob_docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, onboarding_pet_id, doc_type, label, file_url, file_mime, uploaded_at
             FROM {$wpdb->prefix}opb_onboarding_documents WHERE inquiry_id = %d",
            $inquiry['id']
        ), ARRAY_A );

        $branch_name = '';
        if ( $inquiry['branch_id'] ) {
            $branch_name = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}opb_branches WHERE id = %d",
                $inquiry['branch_id']
            ) ) ?? '';
        }

        return $this->success( [
            'inquiry'     => [
                'owner_name'         => $inquiry['owner_name'],
                'phone'              => $inquiry['phone'],
                'email'              => $inquiry['email'],
                'pet_name'           => $inquiry['pet_name'],
                'pet_type'           => $inquiry['pet_type'],
                'desired_check_in'   => $inquiry['desired_check_in'],
                'desired_check_out'  => $inquiry['desired_check_out'],
                'message'            => $inquiry['message'],
                'branch_name'        => $branch_name,
                'status'             => $inquiry['status'],
            ],
            'client'      => $ob_client,
            'pets'        => $ob_pets,
            'documents'   => $ob_docs,
            'tc_version'  => OPB_Onboarding_Handler::TC_VERSION,
            'facility'    => get_bloginfo( 'name' ) ?: 'Onukonu Pet Boarding',
        ] );
    }

    // ── Submit Onboarding (client + pets) ──────────────────────────────────────

    public function submit_onboarding( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $token   = $r['token'];
        $inquiry = $this->get_inquiry_by_token( $token );
        if ( is_wp_error( $inquiry ) ) return $inquiry;

        if ( in_array( $inquiry['status'], [ 'CONVERTED', 'REJECTED', 'ARCHIVED' ], true ) ) {
            return $this->error( 'closed', 'This onboarding link is no longer active.', 410 );
        }

        $d = $r->get_json_params() ?? [];

        // ── Upsert onboarding client ───────────────────────────────────────────
        $client_data = [
            'inquiry_id'              => $inquiry['id'],
            'name'                    => sanitize_text_field( $d['name'] ?? $inquiry['owner_name'] ),
            'phone'                   => sanitize_text_field( $d['phone'] ?? $inquiry['phone'] ),
            'email'                   => sanitize_email( $d['email'] ?? $inquiry['email'] ?? '' ),
            'address'                 => sanitize_textarea_field( $d['address'] ?? '' ),
            'local_guardian_name'     => sanitize_text_field( $d['local_guardian_name'] ?? '' ),
            'local_guardian_contact'  => sanitize_text_field( $d['local_guardian_contact'] ?? '' ),
            'emergency_contact_name'  => sanitize_text_field( $d['emergency_contact_name'] ?? '' ),
            'emergency_contact_phone' => sanitize_text_field( $d['emergency_contact_phone'] ?? '' ),
            'notes'                   => sanitize_textarea_field( $d['notes'] ?? '' ),
            'completed_at'            => current_time( 'mysql' ),
        ];

        $existing_oc = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_onboarding_clients WHERE inquiry_id = %d",
            $inquiry['id']
        ) );

        if ( $existing_oc ) {
            $wpdb->update( "{$wpdb->prefix}opb_onboarding_clients", $client_data, [ 'inquiry_id' => $inquiry['id'] ] );
            $ob_client_id = (int) $existing_oc;
        } else {
            $wpdb->insert( "{$wpdb->prefix}opb_onboarding_clients", $client_data );
            $ob_client_id = (int) $wpdb->insert_id;
        }

        // ── Upsert pets ────────────────────────────────────────────────────────
        $pets     = is_array( $d['pets'] ?? null ) ? $d['pets'] : [];
        $pet_ids  = [];

        // Remove pets not in submitted list (by id) — re-sync approach
        $submitted_pet_ids = array_filter( array_column( $pets, 'id' ) );
        if ( $submitted_pet_ids ) {
            $placeholders = implode( ',', array_fill( 0, count( $submitted_pet_ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}opb_onboarding_pets
                 WHERE inquiry_id = %d AND id NOT IN ($placeholders)",
                array_merge( [ $inquiry['id'] ], $submitted_pet_ids )
            ) );
        } else {
            $wpdb->delete( "{$wpdb->prefix}opb_onboarding_pets", [ 'inquiry_id' => $inquiry['id'] ] );
        }

        foreach ( $pets as $pet ) {
            $pet_row = [
                'inquiry_id'              => $inquiry['id'],
                'onboarding_client_id'    => $ob_client_id,
                'name'                    => sanitize_text_field( $pet['name'] ?? '' ),
                'pet_type'                => in_array( $pet['pet_type'] ?? '', [ 'Dog', 'Cat', 'Other' ], true ) ? $pet['pet_type'] : 'Other',
                'breed'                   => sanitize_text_field( $pet['breed'] ?? '' ),
                'gender'                  => in_array( $pet['gender'] ?? '', [ 'Male', 'Female', 'Unknown' ], true ) ? $pet['gender'] : 'Unknown',
                'breed_size'              => sanitize_text_field( $pet['breed_size'] ?? '' ),
                'coat'                    => sanitize_text_field( $pet['coat'] ?? '' ),
                'weight_kg'               => is_numeric( $pet['weight_kg'] ?? '' ) ? (float) $pet['weight_kg'] : null,
                'birthday'                => $this->safe_date( $pet['birthday'] ?? '' ),
                'microchip_number'        => sanitize_text_field( $pet['microchip_number'] ?? '' ),
                'neutered_or_spayed'      => isset( $pet['neutered_or_spayed'] ) ? (int) $pet['neutered_or_spayed'] : null,
                'vaccination_status'      => in_array( $pet['vaccination_status'] ?? '', [ 'Vaccinated', 'Not vaccinated', 'Unknown' ], true ) ? $pet['vaccination_status'] : 'Unknown',
                'anti_rabies_date'        => $this->safe_date( $pet['anti_rabies_date'] ?? '' ),
                'dhppil_date'             => $this->safe_date( $pet['dhppil_date'] ?? '' ),
                'corona_date'             => $this->safe_date( $pet['corona_date'] ?? '' ),
                'kennel_cough_date'       => $this->safe_date( $pet['kennel_cough_date'] ?? '' ),
                'tick_prevention'         => (int) ( $pet['tick_prevention'] ?? 0 ),
                'last_tick_prevention_date' => $this->safe_date( $pet['last_tick_prevention_date'] ?? '' ),
                'tick_prevention_method'  => sanitize_text_field( $pet['tick_prevention_method'] ?? '' ),
                'ongoing_medication'      => (int) ( $pet['ongoing_medication'] ?? 0 ),
                'medication_detail'       => sanitize_textarea_field( $pet['medication_detail'] ?? '' ),
                'major_illness_history'   => sanitize_textarea_field( $pet['major_illness_history'] ?? '' ),
                'deworming_date'          => $this->safe_date( $pet['deworming_date'] ?? '' ),
                'vet_name'                => sanitize_text_field( $pet['vet_name'] ?? '' ),
                'vet_contact'             => sanitize_text_field( $pet['vet_contact'] ?? '' ),
                'dietary_preference'      => sanitize_text_field( $pet['dietary_preference'] ?? '' ),
                'additional_meals'        => sanitize_textarea_field( $pet['additional_meals'] ?? '' ),
                'preferences_or_allergies'=> sanitize_textarea_field( $pet['preferences_or_allergies'] ?? '' ),
                'first_walk_schedule'     => sanitize_text_field( $pet['first_walk_schedule'] ?? '' ),
                'second_walk_schedule'    => sanitize_text_field( $pet['second_walk_schedule'] ?? '' ),
                'third_walk_schedule'     => sanitize_text_field( $pet['third_walk_schedule'] ?? '' ),
                'consent_photos'          => (int) ( $pet['consent_photos'] ?? 0 ),
                'social_media_handle'     => sanitize_text_field( $pet['social_media_handle'] ?? '' ),
                'special_occasion'        => sanitize_text_field( $pet['special_occasion'] ?? '' ),
                'special_occasion_date'   => $this->safe_date( $pet['special_occasion_date'] ?? '' ),
                'additional_notes'        => sanitize_textarea_field( $pet['additional_notes'] ?? '' ),
            ];

            if ( ! empty( $pet['id'] ) ) {
                $wpdb->update( "{$wpdb->prefix}opb_onboarding_pets", $pet_row, [
                    'id'         => (int) $pet['id'],
                    'inquiry_id' => $inquiry['id'],
                ] );
                $pet_ids[] = (int) $pet['id'];
            } else {
                $wpdb->insert( "{$wpdb->prefix}opb_onboarding_pets", $pet_row );
                $pet_ids[] = (int) $wpdb->insert_id;
            }
        }

        // ── Update inquiry status ──────────────────────────────────────────────
        $new_status = 'ONBOARDING_COMPLETED';
        if ( ! in_array( $inquiry['status'], [ 'ONBOARDING_COMPLETED', 'READY_FOR_REVIEW', 'CONVERTED' ], true ) ) {
            $wpdb->update(
                "{$wpdb->prefix}opb_inquiries",
                [ 'status' => $new_status ],
                [ 'id'     => $inquiry['id'] ]
            );
        }

        return $this->success( [
            'message'          => 'Onboarding information saved.',
            'onboarding_client_id' => $ob_client_id,
            'pet_ids'          => $pet_ids,
        ] );
    }

    // ── Upload Document ────────────────────────────────────────────────────────

    public function upload_document( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $token   = $r['token'];
        $inquiry = $this->get_inquiry_by_token( $token );
        if ( is_wp_error( $inquiry ) ) return $inquiry;

        if ( in_array( $inquiry['status'], [ 'CONVERTED', 'REJECTED', 'ARCHIVED' ], true ) ) {
            return $this->error( 'closed', 'This onboarding link is no longer active.', 410 );
        }

        $files = $r->get_file_params();
        if ( empty( $files['file'] ) ) {
            return $this->error( 'no_file', 'No file uploaded.' );
        }

        $result = OPB_Onboarding_Handler::handle_upload( $files['file'], $token );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $doc_type        = sanitize_text_field( $r->get_param( 'doc_type' ) ?? 'other' );
        $label           = sanitize_text_field( $r->get_param( 'label' ) ?? '' );
        $ob_pet_id_param = $r->get_param( 'onboarding_pet_id' );
        $ob_pet_id       = $ob_pet_id_param ? (int) $ob_pet_id_param : null;

        $valid_types = [ 'owner_id', 'vaccination_card', 'rabies_cert', 'kennel_cough_cert', 'medical_report', 'pet_photo', 'other' ];
        if ( ! in_array( $doc_type, $valid_types, true ) ) {
            $doc_type = 'other';
        }

        $wpdb->insert( "{$wpdb->prefix}opb_onboarding_documents", [
            'inquiry_id'       => $inquiry['id'],
            'onboarding_pet_id'=> $ob_pet_id,
            'doc_type'         => $doc_type,
            'label'            => $label,
            'file_url'         => $result['url'],
            'file_path'        => $result['path'],
            'file_mime'        => $result['mime'],
        ] );

        return $this->success( [
            'id'       => (int) $wpdb->insert_id,
            'file_url' => $result['url'],
            'file_mime'=> $result['mime'],
            'doc_type' => $doc_type,
            'label'    => $label,
        ], 201 );
    }

    // ── Accept Terms ───────────────────────────────────────────────────────────

    public function accept_terms( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        global $wpdb;

        $token   = $r['token'];
        $inquiry = $this->get_inquiry_by_token( $token );
        if ( is_wp_error( $inquiry ) ) return $inquiry;

        $existing_oc = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_onboarding_clients WHERE inquiry_id = %d",
            $inquiry['id']
        ) );

        $tc_data = [
            'tc_accepted'    => 1,
            'tc_accepted_at' => current_time( 'mysql' ),
            'tc_version'     => OPB_Onboarding_Handler::TC_VERSION,
            'tc_ip'          => $this->get_ip(),
        ];

        if ( $existing_oc ) {
            $wpdb->update( "{$wpdb->prefix}opb_onboarding_clients", $tc_data, [ 'inquiry_id' => $inquiry['id'] ] );
        } else {
            $wpdb->insert( "{$wpdb->prefix}opb_onboarding_clients", array_merge(
                [ 'inquiry_id' => $inquiry['id'] ],
                $tc_data
            ) );
        }

        // Advance status if ready
        if ( $inquiry['status'] === 'ONBOARDING_COMPLETED' ) {
            $wpdb->update(
                "{$wpdb->prefix}opb_inquiries",
                [ 'status' => 'READY_FOR_REVIEW' ],
                [ 'id'     => $inquiry['id'] ]
            );
        }

        return $this->success( [ 'message' => 'Terms accepted. Thank you.' ] );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function get_inquiry_by_token( string $token ): array|WP_Error {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_inquiries WHERE token = %s LIMIT 1",
            $token
        ), ARRAY_A );

        if ( ! $row ) {
            return $this->error( 'not_found', 'Onboarding link not found or expired.', 404 );
        }
        return $row;
    }

    private function safe_date( string $val ): ?string {
        if ( ! $val ) return null;
        $ts = strtotime( $val );
        return $ts ? date( 'Y-m-d', $ts ) : null;
    }

    private function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
