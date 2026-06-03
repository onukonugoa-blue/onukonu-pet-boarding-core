<?php
/**
 * OPB_Onboarding_Handler
 *
 * Static utility class: token generation, WhatsApp URL building,
 * and the authoritative Convert-to-Client workflow.
 */
class OPB_Onboarding_Handler {

    const TC_VERSION = '1.0';

    // ── Token ──────────────────────────────────────────────────────────────────

    public static function generate_token(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    public static function onboarding_url( string $token ): string {
        return home_url( '/opb-onboard/' . $token . '/' );
    }

    // ── WhatsApp ───────────────────────────────────────────────────────────────

    public static function whatsapp_url( string $phone, string $client_name, string $token ): string {
        $facility = get_bloginfo( 'name' ) ?: 'Onukonu Pet Boarding';
        $link     = self::onboarding_url( $token );

        $message = "Hello {$client_name},\n\n"
            . "Thank you for your interest in boarding with us.\n\n"
            . "Please complete your onboarding form and review the boarding terms using the secure link below:\n\n"
            . "{$link}\n\n"
            . "Once submitted, our team will review your information and contact you regarding your booking.\n\n"
            . "Thank you.\n{$facility}";

        $clean_phone = preg_replace( '/[^0-9+]/', '', $phone );
        return 'https://wa.me/' . ltrim( $clean_phone, '+' ) . '?text=' . rawurlencode( $message );
    }

    // ── Duplicate check ────────────────────────────────────────────────────────

    public static function find_duplicate_client( string $phone, string $email = '' ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.id, c.name, c.phone, c.email, b.name AS branch_name,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets p WHERE p.client_id = c.id AND p.is_active = 1) AS pet_count,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings bk WHERE bk.client_id = c.id) AS booking_count
             FROM {$wpdb->prefix}opb_clients c
             LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = c.home_branch_id
             WHERE c.phone = %s
             LIMIT 1",
            sanitize_text_field( $phone )
        ), ARRAY_A );

        if ( $row ) {
            $row['match_type'] = 'phone';
            return $row;
        }

        if ( $email ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT c.id, c.name, c.phone, c.email, b.name AS branch_name,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}opb_pets p WHERE p.client_id = c.id AND p.is_active = 1) AS pet_count,
                        (SELECT COUNT(*) FROM {$wpdb->prefix}opb_bookings bk WHERE bk.client_id = c.id) AS booking_count
                 FROM {$wpdb->prefix}opb_clients c
                 LEFT JOIN {$wpdb->prefix}opb_branches b ON b.id = c.home_branch_id
                 WHERE c.email = %s AND c.email != ''
                 LIMIT 1",
                sanitize_email( $email )
            ), ARRAY_A );

            if ( $row ) {
                $row['match_type'] = 'email';
                return $row;
            }
        }

        return null;
    }

    // ── Conversion ─────────────────────────────────────────────────────────────

    /**
     * Convert an inquiry into operational Client + Pets + Documents.
     * This is the ONLY place operational records are created from onboarding data.
     * Must be called by authenticated staff with opb_manage_clients capability.
     *
     * @return array{client_id: int, pet_ids: int[], doc_ids: int[]}
     * @throws RuntimeException on failure
     */
    public static function convert( int $inquiry_id, int $branch_id, int $staff_user_id ): array {
        global $wpdb;

        $inquiry = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_inquiries WHERE id = %d",
            $inquiry_id
        ), ARRAY_A );

        if ( ! $inquiry ) {
            throw new RuntimeException( 'Inquiry not found.' );
        }

        if ( $inquiry['status'] === 'CONVERTED' ) {
            throw new RuntimeException( 'Inquiry has already been converted.' );
        }

        $ob_client = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_clients WHERE inquiry_id = %d",
            $inquiry_id
        ), ARRAY_A );

        if ( ! $ob_client ) {
            throw new RuntimeException( 'Onboarding client data not found. Customer must complete the onboarding form first.' );
        }

        $ob_pets = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_pets WHERE inquiry_id = %d ORDER BY id ASC",
            $inquiry_id
        ), ARRAY_A );

        $ob_docs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_onboarding_documents WHERE inquiry_id = %d",
            $inquiry_id
        ), ARRAY_A );

        // Determine phone — prefer onboarding data over inquiry
        $phone = $ob_client['phone'] ?: $inquiry['phone'];
        $name  = $ob_client['name']  ?: $inquiry['owner_name'];

        // ── 1. Create Client ───────────────────────────────────────────────────
        $inserted = $wpdb->insert( "{$wpdb->prefix}opb_clients", [
            'home_branch_id'         => $branch_id,
            'name'                   => sanitize_text_field( $name ),
            'phone'                  => sanitize_text_field( $phone ),
            'email'                  => sanitize_email( $ob_client['email'] ?? '' ),
            'address'                => sanitize_textarea_field( $ob_client['address'] ?? '' ),
            'local_guardian_name'    => sanitize_text_field( $ob_client['local_guardian_name'] ?? '' ),
            'local_guardian_contact' => sanitize_text_field( $ob_client['local_guardian_contact'] ?? '' ),
            'notes'                  => sanitize_textarea_field( $ob_client['notes'] ?? '' ),
            'tc_accepted'            => (int) $ob_client['tc_accepted'],
            'onboarding_date'        => current_time( 'Y-m-d' ),
            'status'                 => 'active',
        ] );

        if ( ! $inserted ) {
            throw new RuntimeException( 'Failed to create client: ' . $wpdb->last_error );
        }

        $client_id = (int) $wpdb->insert_id;

        // ── 2. Create Pets ─────────────────────────────────────────────────────
        $pet_ids    = [];
        $pet_id_map = []; // onboarding_pet_id => operational_pet_id

        foreach ( $ob_pets as $op ) {
            $wpdb->insert( "{$wpdb->prefix}opb_pets", [
                'client_id'               => $client_id,
                'name'                    => sanitize_text_field( $op['name'] ?: 'Pet' ),
                'pet_type'                => $op['pet_type'] ?: 'Other',
                'breed'                   => sanitize_text_field( $op['breed'] ?? '' ),
                'gender'                  => $op['gender'] ?: 'Unknown',
                'breed_size'              => $op['breed_size'] ?? null,
                'coat'                    => sanitize_text_field( $op['coat'] ?? '' ),
                'weight_kg'               => $op['weight_kg'] ? (float) $op['weight_kg'] : null,
                'birthday'                => $op['birthday'] ?: null,
                'microchip_number'        => sanitize_text_field( $op['microchip_number'] ?? '' ),
                'neutered_or_spayed'      => isset( $op['neutered_or_spayed'] ) ? (int) $op['neutered_or_spayed'] : null,
                'vaccination_status'      => $op['vaccination_status'] ?: 'Unknown',
                'anti_rabies_date'        => $op['anti_rabies_date'] ?: null,
                'dhppil_date'             => $op['dhppil_date'] ?: null,
                'corona_date'             => $op['corona_date'] ?: null,
                'kennel_cough_date'       => $op['kennel_cough_date'] ?: null,
                'tick_prevention'         => (int) ( $op['tick_prevention'] ?? 0 ),
                'last_tick_prevention_date' => $op['last_tick_prevention_date'] ?: null,
                'tick_prevention_method'  => sanitize_text_field( $op['tick_prevention_method'] ?? '' ),
                'ongoing_medication'      => (int) ( $op['ongoing_medication'] ?? 0 ),
                'medication_detail'       => sanitize_textarea_field( $op['medication_detail'] ?? '' ),
                'major_illness_history'   => sanitize_textarea_field( $op['major_illness_history'] ?? '' ),
                'deworming_date'          => $op['deworming_date'] ?: null,
                'vet_name'                => sanitize_text_field( $op['vet_name'] ?? '' ),
                'vet_contact'             => sanitize_text_field( $op['vet_contact'] ?? '' ),
                'dietary_preference'      => sanitize_text_field( $op['dietary_preference'] ?? '' ),
                'additional_meals'        => sanitize_textarea_field( $op['additional_meals'] ?? '' ),
                'preferences_or_allergies'=> sanitize_textarea_field( $op['preferences_or_allergies'] ?? '' ),
                'first_walk_schedule'     => sanitize_text_field( $op['first_walk_schedule'] ?? '' ),
                'second_walk_schedule'    => sanitize_text_field( $op['second_walk_schedule'] ?? '' ),
                'third_walk_schedule'     => sanitize_text_field( $op['third_walk_schedule'] ?? '' ),
                'consent_photos'          => (int) ( $op['consent_photos'] ?? 0 ),
                'social_media_handle'     => sanitize_text_field( $op['social_media_handle'] ?? '' ),
                'special_occasion'        => sanitize_text_field( $op['special_occasion'] ?? '' ),
                'special_occasion_date'   => $op['special_occasion_date'] ?: null,
                'is_active'               => 1,
            ] );

            $new_pet_id             = (int) $wpdb->insert_id;
            $pet_ids[]              = $new_pet_id;
            $pet_id_map[ $op['id'] ] = $new_pet_id;
        }

        // ── 3. Transfer Documents ──────────────────────────────────────────────
        $doc_ids = [];
        foreach ( $ob_docs as $doc ) {
            $operational_pet_id = null;
            if ( $doc['onboarding_pet_id'] && isset( $pet_id_map[ $doc['onboarding_pet_id'] ] ) ) {
                $operational_pet_id = $pet_id_map[ $doc['onboarding_pet_id'] ];
            } elseif ( ! empty( $pet_ids ) ) {
                $operational_pet_id = $pet_ids[0];
            }

            if ( ! $operational_pet_id ) {
                continue;
            }

            $doc_type_map = [
                'owner_id'          => 'other',
                'vaccination_card'  => 'vaccination',
                'rabies_cert'       => 'vaccination',
                'kennel_cough_cert' => 'vaccination',
                'medical_report'    => 'other',
                'pet_photo'         => 'photo',
                'other'             => 'other',
            ];

            $wpdb->insert( "{$wpdb->prefix}opb_pet_documents", [
                'pet_id'      => $operational_pet_id,
                'doc_type'    => $doc_type_map[ $doc['doc_type'] ] ?? 'other',
                'label'       => sanitize_text_field( $doc['label'] ?? '' ),
                'file_url'    => esc_url_raw( $doc['file_url'] ),
                'file_mime'   => sanitize_text_field( $doc['file_mime'] ?? '' ),
                'seq_number'  => 1,
                'uploaded_by' => $staff_user_id,
            ] );

            $doc_ids[] = (int) $wpdb->insert_id;
        }

        // ── 4. Mark Inquiry Converted ──────────────────────────────────────────
        $wpdb->update(
            "{$wpdb->prefix}opb_inquiries",
            [
                'status'              => 'CONVERTED',
                'converted_client_id' => $client_id,
                'converted_at'        => current_time( 'mysql' ),
                'converted_by'        => $staff_user_id,
            ],
            [ 'id' => $inquiry_id ]
        );

        return [
            'client_id' => $client_id,
            'pet_ids'   => $pet_ids,
            'doc_ids'   => $doc_ids,
        ];
    }

    // ── Upload helper ──────────────────────────────────────────────────────────

    /**
     * Handle a file upload for onboarding documents.
     * Stores in wp-content/uploads/opb-onboarding/{token}/ directory.
     * Returns ['url' => ..., 'path' => ..., 'mime' => ...] or WP_Error.
     */
    public static function handle_upload( array $file, string $token ): array|WP_Error {
        $allowed_mime = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
        ];

        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed_mime, true ) ) {
            return new WP_Error( 'invalid_file', 'Only images and PDFs are allowed.' );
        }

        if ( $file['size'] > 10 * 1024 * 1024 ) {
            return new WP_Error( 'file_too_large', 'Maximum file size is 10 MB.' );
        }

        $upload_dir  = wp_upload_dir();
        $target_dir  = $upload_dir['basedir'] . '/opb-onboarding/' . sanitize_file_name( $token );
        $target_url  = $upload_dir['baseurl'] . '/opb-onboarding/' . sanitize_file_name( $token );

        if ( ! wp_mkdir_p( $target_dir ) ) {
            return new WP_Error( 'upload_dir', 'Could not create upload directory.' );
        }

        $ext      = self::mime_to_ext( $mime );
        $filename = sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ) . '-' . wp_generate_password( 8, false ) . $ext;
        $dest     = $target_dir . '/' . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'upload_failed', 'File could not be saved.' );
        }

        return [
            'url'  => $target_url . '/' . $filename,
            'path' => $dest,
            'mime' => $mime,
        ];
    }

    private static function mime_to_ext( string $mime ): string {
        return match ( $mime ) {
            'image/jpeg'      => '.jpg',
            'image/png'       => '.png',
            'image/gif'       => '.gif',
            'image/webp'      => '.webp',
            'application/pdf' => '.pdf',
            default           => '',
        };
    }
}
