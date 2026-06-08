<?php
/**
 * OPB_Customizations
 *
 * Central registry for all business-facing configurable content.
 * Provides a flat key/value store backed by opb_customizations table,
 * with registry-defined defaults and a shared template renderer.
 *
 * ALL template rendering must go through render() / render_string().
 * Preview and production rendering use the same code path.
 */
class OPB_Customizations {

    const VALID_PLACEHOLDERS = [
        'CLIENT_NAME',
        'FACILITY_NAME',
        'ONBOARDING_LINK',
        'PHONE',
        'EMAIL',
        'INVOICE_NUMBER',
        'INVOICE_LINK',
        'INVOICE_TOTAL',
        'INVOICE_PAID',
        'INVOICE_DUE',
        'MY_PETS_URL',
        'SUPPORT_PHONE',
        'SUPPORT_EMAIL',
        'CLIENT_EMAIL',
        'CLIENT_PHONE',
    ];

    const REGISTRY = [

        // ── FACILITY ──────────────────────────────────────────────────────────
        'facility_name' => [
            'category' => 'facility',
            'label'    => 'Facility Name',
            'type'     => 'text',
            'default'  => 'Onukonu Pet Homestyle Boarding',
        ],
        'facility_phone' => [
            'category' => 'facility',
            'label'    => 'Support Phone',
            'type'     => 'text',
            'default'  => '',
        ],
        'facility_email' => [
            'category' => 'facility',
            'label'    => 'Support Email',
            'type'     => 'text',
            'default'  => '',
        ],
        'facility_website' => [
            'category' => 'facility',
            'label'    => 'Website',
            'type'     => 'text',
            'default'  => '',
        ],
        'facility_hours' => [
            'category' => 'facility',
            'label'    => 'Business Hours',
            'type'     => 'textarea',
            'default'  => '',
        ],

        // ── LEGAL ─────────────────────────────────────────────────────────────
        'tc_version' => [
            'category' => 'legal',
            'label'    => 'T&C Version',
            'type'     => 'text',
            'default'  => '1.0',
        ],
        'tc_html' => [
            'category' => 'legal',
            'label'    => 'Boarding Terms & Conditions',
            'type'     => 'richtext',
            'default'  => '<p><strong>1. Health &amp; Vaccination:</strong> All pets must be up-to-date on core vaccinations (Anti-Rabies, DHPPiL, Kennel Cough) before boarding. {{FACILITY_NAME}} reserves the right to refuse boarding to pets that do not meet vaccination requirements.</p>
<p><strong>2. Health Disclosure:</strong> The owner agrees to disclose all known medical conditions, ongoing medications, dietary restrictions, and behavioural issues. {{FACILITY_NAME}} will not be held liable for undisclosed conditions.</p>
<p><strong>3. Emergency Medical Treatment:</strong> In the event of a medical emergency, {{FACILITY_NAME}} will attempt to contact the owner and/or emergency contact immediately. If the owner cannot be reached, {{FACILITY_NAME}} may authorise necessary veterinary treatment at the owner\'s expense.</p>
<p><strong>4. Liability:</strong> {{FACILITY_NAME}} exercises all reasonable care for boarded pets but is not liable for injury, illness, or death resulting from pre-existing conditions, acts of other animals, or circumstances beyond our control.</p>
<p><strong>5. Behaviour:</strong> Pets that display aggressive behaviour posing a risk to staff or other animals may be isolated or returned to the owner. No refund will be issued in such cases.</p>
<p><strong>6. Pick-Up:</strong> Pets not collected within 24 hours after the agreed check-out date without prior notice may incur additional charges. {{FACILITY_NAME}} reserves the right to seek animal control assistance after 72 hours of no contact.</p>
<p><strong>7. Photography &amp; Media:</strong> With consent, {{FACILITY_NAME}} may photograph or video your pet for social media and marketing purposes. Consent can be withdrawn at any time.</p>
<p><strong>8. Payment:</strong> Full payment is due at or before pick-up unless a prior arrangement has been made. Overdue balances may attract late fees.</p>
<p><strong>9. Changes:</strong> {{FACILITY_NAME}} reserves the right to update these terms. Continued use of boarding services constitutes acceptance of current terms.</p>',
        ],
        'privacy_notice' => [
            'category' => 'legal',
            'label'    => 'Privacy Notice',
            'type'     => 'richtext',
            'default'  => '',
        ],
        'additional_policies' => [
            'category' => 'legal',
            'label'    => 'Additional Policies',
            'type'     => 'richtext',
            'default'  => '',
        ],
        'operational_notes' => [
            'category' => 'legal',
            'label'    => 'Operational Notes',
            'type'     => 'richtext',
            'default'  => '',
        ],

        // ── ONBOARDING ────────────────────────────────────────────────────────
        'onboarding_email_subject' => [
            'category' => 'onboarding',
            'label'    => 'Onboarding Email Subject',
            'type'     => 'text',
            'default'  => 'Your onboarding link — {{FACILITY_NAME}}',
        ],
        'onboarding_email_body' => [
            'category' => 'onboarding',
            'label'    => 'Onboarding Email Body',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}},

Thank you for choosing {{FACILITY_NAME}}. We\'ve reviewed your inquiry and we\'re ready to get you onboarded.

Please use the secure link below to complete your pet\'s profile, upload any required documents, and review our boarding terms & conditions.',
        ],
        'onboarding_whatsapp_message' => [
            'category' => 'onboarding',
            'label'    => 'Onboarding WhatsApp Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}}, welcome to {{FACILITY_NAME}}! 🐾

To complete your onboarding, please use this secure link:
{{ONBOARDING_LINK}}

If you have any questions, feel free to reach out.

See you soon!
{{FACILITY_NAME}}',
        ],
        'onboarding_resend_message' => [
            'category' => 'onboarding',
            'label'    => 'Resend Onboarding Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}}, here is your updated onboarding link for {{FACILITY_NAME}}:

{{ONBOARDING_LINK}}

If you have any questions, feel free to reach out.',
        ],
        'onboarding_completion_message' => [
            'category' => 'onboarding',
            'label'    => 'Onboarding Completion Message',
            'type'     => 'textarea',
            'default'  => 'Thank you for completing your onboarding with {{FACILITY_NAME}}. Our team will review your information and contact you to confirm your booking details.',
        ],
        'onboarding_step_instruction' => [
            'category' => 'onboarding',
            'label'    => 'Document Step Instructions',
            'type'     => 'textarea',
            'default'  => 'Upload any relevant documents. All are optional but help speed up your onboarding.',
        ],

        // ── INQUIRY ───────────────────────────────────────────────────────────
        'inquiry_ack_subject' => [
            'category' => 'inquiry',
            'label'    => 'Acknowledgement Email Subject',
            'type'     => 'text',
            'default'  => 'We\'ve received your inquiry — {{FACILITY_NAME}}',
        ],
        'inquiry_ack_message' => [
            'category' => 'inquiry',
            'label'    => 'Acknowledgement Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}}, thank you for reaching out to {{FACILITY_NAME}}! We\'ve received your boarding inquiry and our team will review it and get back to you shortly.',
        ],
        'inquiry_followup_message' => [
            'category' => 'inquiry',
            'label'    => 'Follow-Up Message',
            'type'     => 'textarea',
            'default'  => '',
        ],
        'inquiry_acceptance_message' => [
            'category' => 'inquiry',
            'label'    => 'Acceptance Message',
            'type'     => 'textarea',
            'default'  => '',
        ],
        'inquiry_rejection_message' => [
            'category' => 'inquiry',
            'label'    => 'Rejection Message',
            'type'     => 'textarea',
            'default'  => '',
        ],

        // ── INVOICE BRANDING ──────────────────────────────────────────────────
        'invoice_banner_image' => [
            'category' => 'invoice_branding',
            'label'    => 'Facility Banner Image',
            'type'     => 'media',
            'default'  => '',
        ],
        'invoice_logo_image' => [
            'category' => 'invoice_branding',
            'label'    => 'Facility Logo',
            'type'     => 'media',
            'default'  => '',
        ],
        'invoice_payment_qr_image' => [
            'category' => 'invoice_branding',
            'label'    => 'Payment QR Image',
            'type'     => 'media',
            'default'  => '',
        ],
        'invoice_footer_text' => [
            'category' => 'invoice_branding',
            'label'    => 'Invoice Footer Text',
            'type'     => 'textarea',
            'default'  => '',
        ],
        'invoice_payment_instructions' => [
            'category' => 'invoice_branding',
            'label'    => 'Payment Instructions',
            'type'     => 'textarea',
            'default'  => 'Payment is accepted via Cash, UPI, or Bank Transfer.',
        ],
        'invoice_bank_details' => [
            'category' => 'invoice_branding',
            'label'    => 'Bank Details',
            'type'     => 'textarea',
            'default'  => '',
        ],
        'invoice_upi_id' => [
            'category' => 'invoice_branding',
            'label'    => 'UPI ID',
            'type'     => 'text',
            'default'  => '',
        ],
        'invoice_thank_you_message' => [
            'category' => 'invoice_branding',
            'label'    => 'Thank You Message',
            'type'     => 'text',
            'default'  => 'Thank you for choosing {{FACILITY_NAME}}!',
        ],
        'invoice_terms_text' => [
            'category' => 'invoice_branding',
            'label'    => 'Invoice Terms',
            'type'     => 'richtext',
            'default'  => '',
        ],

        // ── INVOICE ───────────────────────────────────────────────────────────
        'invoice_email_subject' => [
            'category' => 'invoice',
            'label'    => 'Email Subject',
            'type'     => 'text',
            'default'  => 'Invoice #{{INVOICE_NUMBER}} from {{FACILITY_NAME}}',
        ],
        'invoice_email_intro' => [
            'category' => 'invoice',
            'label'    => 'Email Introduction',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}},

Please find your invoice from {{FACILITY_NAME}} below. If you have any questions, feel free to reach out.',
        ],
        'invoice_footer_note' => [
            'category' => 'invoice',
            'label'    => 'Invoice Footer Note',
            'type'     => 'textarea',
            'default'  => 'Thank you for choosing {{FACILITY_NAME}}! We look forward to seeing you and your pet again.',
        ],
        'invoice_payment_note' => [
            'category' => 'invoice',
            'label'    => 'Payment Note',
            'type'     => 'textarea',
            'default'  => 'Payment is due at the time of pick-up. Please contact us if you have any questions about this invoice.',
        ],
        'invoice_whatsapp_message' => [
            'category' => 'invoice',
            'label'    => 'WhatsApp Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}}, here is your invoice #{{INVOICE_NUMBER}} from {{FACILITY_NAME}}.

💰 Total: {{INVOICE_TOTAL}}
✅ Paid:  {{INVOICE_PAID}}
🔴 Due:   {{INVOICE_DUE}}

View your invoice here:
{{INVOICE_LINK}}

Thank you! 🐾
{{FACILITY_NAME}}',
        ],

        // ── CLIENT PORTAL ─────────────────────────────────────────────────────
        'client_portal_whatsapp_message' => [
            'category' => 'client_portal',
            'label'    => 'My Pets Access WhatsApp Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{CLIENT_NAME}}, here is your secure My Pets portal for {{FACILITY_NAME}}:

{{MY_PETS_URL}}

Use this link to view your pet profiles, upcoming bookings, and invoices at any time.

{{FACILITY_NAME}}',
        ],
        'client_support_email_subject' => [
            'category' => 'client_portal',
            'label'    => 'Support Email Subject',
            'type'     => 'text',
            'default'  => 'Support Request — {{FACILITY_NAME}}',
        ],
        'client_support_email_body' => [
            'category' => 'client_portal',
            'label'    => 'Support Email Body',
            'type'     => 'textarea',
            'default'  => 'Hi {{FACILITY_NAME}} team,

I have a query regarding my pets / booking.

Name: {{CLIENT_NAME}}
Email: {{CLIENT_EMAIL}}
Phone: {{CLIENT_PHONE}}

Please let me know how you can help.

Thank you',
        ],
        'client_support_whatsapp_message' => [
            'category' => 'client_portal',
            'label'    => 'Support WhatsApp Message',
            'type'     => 'textarea',
            'default'  => 'Hi {{FACILITY_NAME}} team, I\'d like some help regarding my pets / booking. My name is {{CLIENT_NAME}} ({{CLIENT_EMAIL}}).',
        ],
    ];

    // ── Core accessors ────────────────────────────────────────────────────────

    /**
     * Get a single setting value. Returns registry default when no DB row exists.
     */
    public static function get( string $key ): string {
        if ( ! isset( self::REGISTRY[ $key ] ) ) {
            return '';
        }
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}opb_customizations WHERE setting_key = %s",
            $key
        ) );
        if ( $row !== null ) {
            return (string) $row;
        }
        return (string) self::REGISTRY[ $key ]['default'];
    }

    /**
     * Get all settings, each merged with registry metadata.
     * Returns an associative array keyed by setting_key.
     *
     * @return array<string, array{key:string, category:string, label:string, type:string, value:string, is_default:bool, updated_at:string|null, updated_by:int|null}>
     */
    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT setting_key, setting_value, category, updated_at, updated_by
             FROM {$wpdb->prefix}opb_customizations",
            ARRAY_A
        );

        $db_map = [];
        foreach ( $rows as $row ) {
            $db_map[ $row['setting_key'] ] = $row;
        }

        $result = [];
        foreach ( self::REGISTRY as $key => $meta ) {
            $db            = $db_map[ $key ] ?? null;
            $result[ $key ] = [
                'key'        => $key,
                'category'   => $meta['category'],
                'label'      => $meta['label'],
                'type'       => $meta['type'],
                'value'      => $db ? (string) $db['setting_value'] : (string) $meta['default'],
                'is_default' => $db === null,
                'updated_at' => $db['updated_at'] ?? null,
                'updated_by' => $db ? ( (int) $db['updated_by'] ?: null ) : null,
            ];
        }
        return $result;
    }

    /**
     * Persist a setting value.
     */
    public static function set( string $key, string $value, int $user_id = 0 ): bool {
        if ( ! isset( self::REGISTRY[ $key ] ) ) {
            return false;
        }
        global $wpdb;
        $uid      = $user_id ?: get_current_user_id();
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}opb_customizations WHERE setting_key = %s",
            $key
        ) );

        if ( $existing ) {
            return (bool) $wpdb->update(
                "{$wpdb->prefix}opb_customizations",
                [
                    'setting_value' => $value,
                    'category'      => self::REGISTRY[ $key ]['category'],
                    'updated_at'    => current_time( 'mysql' ),
                    'updated_by'    => $uid,
                ],
                [ 'setting_key' => $key ]
            );
        }

        return (bool) $wpdb->insert(
            "{$wpdb->prefix}opb_customizations",
            [
                'setting_key'   => $key,
                'setting_value' => $value,
                'category'      => self::REGISTRY[ $key ]['category'],
                'updated_at'    => current_time( 'mysql' ),
                'updated_by'    => $uid,
            ]
        );
    }

    // ── Template engine ───────────────────────────────────────────────────────

    /**
     * Render a stored template by key with the given context.
     * This is the canonical entry point — preview and production use the same path.
     */
    public static function render( string $key, array $context = [] ): string {
        return self::render_string( self::get( $key ), $context );
    }

    /**
     * Render an arbitrary template string with context.
     * Replaces {{PLACEHOLDER}} tokens with matching context values.
     * FACILITY_NAME is always injected from the registry/DB when not provided.
     */
    public static function render_string( string $template, array $context = [] ): string {
        if ( ! isset( $context['FACILITY_NAME'] ) ) {
            $context['FACILITY_NAME'] = self::facility_name();
        }
        foreach ( $context as $placeholder => $value ) {
            $template = str_replace( '{{' . $placeholder . '}}', (string) $value, $template );
        }
        return $template;
    }

    /**
     * Validate placeholders in a template string.
     *
     * @return string[] Invalid placeholder names found ({{KEY}} where KEY is not in VALID_PLACEHOLDERS).
     */
    public static function validate_placeholders( string $text ): array {
        preg_match_all( '/\{\{([A-Z0-9_]+)\}\}/', $text, $matches );
        $found   = $matches[1] ?? [];
        $invalid = [];
        foreach ( $found as $name ) {
            if ( ! in_array( $name, self::VALID_PLACEHOLDERS, true ) ) {
                $invalid[] = $name;
            }
        }
        return array_values( array_unique( $invalid ) );
    }

    // ── Media helpers ─────────────────────────────────────────────────────────

    /**
     * Resolve a media setting to a public URL.
     * The stored value is a WordPress attachment ID (integer as string).
     */
    public static function get_media_url( string $key ): string {
        $attachment_id = (int) self::get( $key );
        if ( ! $attachment_id ) {
            return '';
        }
        $url = wp_get_attachment_url( $attachment_id );
        return $url ?: '';
    }

    /**
     * Resolve a media setting to the absolute server file path.
     * Returns empty string if the attachment or file cannot be found.
     */
    public static function get_media_path( string $key ): string {
        $attachment_id = (int) self::get( $key );
        if ( ! $attachment_id ) {
            return '';
        }
        $file = get_attached_file( $attachment_id );
        return ( $file && file_exists( $file ) ) ? $file : '';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the facility name: DB customization → WP site name → hardcoded fallback.
     * Uses a direct DB query to avoid recursion when building render context.
     */
    public static function facility_name(): string {
        global $wpdb;
        $custom = $wpdb->get_var( $wpdb->prepare(
            "SELECT setting_value FROM {$wpdb->prefix}opb_customizations WHERE setting_key = %s",
            'facility_name'
        ) );
        if ( $custom !== null && $custom !== '' ) {
            return (string) $custom;
        }
        return get_bloginfo( 'name' ) ?: (string) self::REGISTRY['facility_name']['default'];
    }

    /**
     * Current T&C version: DB customization → constant fallback.
     */
    public static function tc_version(): string {
        $v = self::get( 'tc_version' );
        return $v !== '' ? $v : OPB_Onboarding_Handler::TC_VERSION;
    }

    /**
     * Sample context used for preview rendering.
     */
    public static function sample_context(): array {
        return [
            'CLIENT_NAME'     => 'Demo Customer',
            'FACILITY_NAME'   => 'Demo Facility',
            'ONBOARDING_LINK' => 'https://example.com/opb-onboard/abc123demo',
            'PHONE'           => '+91 98765 43210',
            'EMAIL'           => 'demo@example.com',
            'INVOICE_NUMBER'  => 'INV-0042',
            'INVOICE_LINK'    => 'https://example.com/opb-invoice/abc123demo/',
            'INVOICE_TOTAL'   => '₹4,500.00',
            'INVOICE_PAID'    => '₹2,000.00',
            'INVOICE_DUE'     => '₹2,500.00',
            'MY_PETS_URL'     => 'https://example.com/my-pets/',
            'SUPPORT_PHONE'   => '+91 98765 43210',
            'SUPPORT_EMAIL'   => 'support@example.com',
            'CLIENT_EMAIL'    => 'demo@example.com',
            'CLIENT_PHONE'    => '+91 98765 43210',
        ];
    }
}
