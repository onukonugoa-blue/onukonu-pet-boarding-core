<?php
/**
 * OPB_Notifications
 *
 * Sends wp_mail() notifications for key pipeline events.
 *
 * Events:
 *   notify_new_inquiry()              — fired when a public inquiry is submitted (staff)
 *   notify_customer_inquiry_received() — confirmation sent to the customer on inquiry submit
 *   notify_onboarding_complete()      — fired when a customer accepts T&C (READY_FOR_REVIEW)
 */
class OPB_Notifications {

    // ── Public entry-points ────────────────────────────────────────────────────

    /**
     * New inquiry submitted via the public form.
     *
     * @param array $inquiry  Row from opb_inquiries (as inserted, id available).
     */
    public static function notify_new_inquiry( array $inquiry ): void {
        $facility   = self::facility_name();
        $subject    = "[{$facility}] New Inquiry from {$inquiry['owner_name']}";
        $admin_url  = admin_url( 'admin.php?page=opb-admin#/inquiries/' . $inquiry['id'] );

        $pet_line = '';
        if ( ! empty( $inquiry['pet_name'] ) ) {
            $pet_line = '<tr><td style="padding:4px 0;color:#6b7280;width:140px">Pet</td>'
                . '<td style="padding:4px 0"><strong>' . esc_html( $inquiry['pet_name'] )
                . ( $inquiry['pet_type'] ? ' (' . esc_html( $inquiry['pet_type'] ) . ')' : '' )
                . '</strong></td></tr>';
        }

        $dates_line = '';
        if ( ! empty( $inquiry['desired_check_in'] ) ) {
            $dates_line = '<tr><td style="padding:4px 0;color:#6b7280">Stay</td>'
                . '<td style="padding:4px 0">'
                . esc_html( $inquiry['desired_check_in'] )
                . ' → ' . esc_html( $inquiry['desired_check_out'] ?? '—' )
                . '</td></tr>';
        }

        $message_line = '';
        if ( ! empty( $inquiry['message'] ) ) {
            $message_line = '<tr><td style="padding:4px 0;color:#6b7280;vertical-align:top">Message</td>'
                . '<td style="padding:4px 0">' . nl2br( esc_html( $inquiry['message'] ) ) . '</td></tr>';
        }

        $existing_warning = '';
        if ( ! empty( $inquiry['existing_client_id'] ) ) {
            $existing_warning = '<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:20px;color:#92400e;">'
                . '⚠ <strong>Existing client detected</strong> — phone or email matches a current client record. Review before onboarding.'
                . '</div>';
        }

        $body = self::wrap_html( $subject,
            $existing_warning
            . '<p style="color:#374151;margin:0 0 20px">A new boarding inquiry has just been submitted via the public form.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:24px">'
            . '<tr><td style="padding:4px 0;color:#6b7280;width:140px">Name</td><td style="padding:4px 0"><strong>' . esc_html( $inquiry['owner_name'] ) . '</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Phone</td><td style="padding:4px 0">' . esc_html( $inquiry['phone'] ) . '</td></tr>'
            . ( $inquiry['email'] ? '<tr><td style="padding:4px 0;color:#6b7280">Email</td><td style="padding:4px 0">' . esc_html( $inquiry['email'] ) . '</td></tr>' : '' )
            . $pet_line
            . $dates_line
            . $message_line
            . '</table>'
            . '<p style="margin:0"><a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#1e3a8a;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600">View Inquiry →</a></p>'
        );

        self::send( self::recipients( $inquiry['branch_id'] ?? null ), $subject, $body );
    }

    /**
     * Confirmation email sent directly to the customer after their inquiry is submitted.
     * Only fires when a valid email address was provided.
     *
     * @param array $inquiry  Row from opb_inquiries (as inserted, id available).
     */
    public static function notify_customer_inquiry_received( array $inquiry ): void {
        $email = $inquiry['email'] ?? '';
        if ( ! is_email( $email ) ) {
            return; // No email provided — skip silently.
        }

        $facility    = self::facility_name();
        $client_name = $inquiry['owner_name'];
        $subject     = "We've received your inquiry — {$facility}";

        $pet_line = '';
        if ( ! empty( $inquiry['pet_name'] ) ) {
            $type_str = ! empty( $inquiry['pet_type'] ) ? ' (' . esc_html( $inquiry['pet_type'] ) . ')' : '';
            $pet_line = '<tr><td style="padding:4px 0;color:#6b7280;width:120px">Pet</td>'
                . '<td style="padding:4px 0"><strong>' . esc_html( $inquiry['pet_name'] ) . $type_str . '</strong></td></tr>';
        }

        $dates_line = '';
        if ( ! empty( $inquiry['desired_check_in'] ) ) {
            $dates_line = '<tr><td style="padding:4px 0;color:#6b7280">Requested stay</td>'
                . '<td style="padding:4px 0">'
                . esc_html( $inquiry['desired_check_in'] )
                . ' → ' . esc_html( $inquiry['desired_check_out'] ?? '—' )
                . '</td></tr>';
        }

        $body = self::wrap_html( $subject,
            '<p style="color:#374151;margin:0 0 16px">Hi <strong>' . esc_html( $client_name ) . '</strong>,</p>'
            . '<p style="color:#374151;margin:0 0 20px">Thank you for reaching out to <strong>' . esc_html( $facility ) . '</strong>! We\'ve received your boarding inquiry and our team will review it and get back to you shortly.</p>'
            . '<table style="width:100%;border-collapse:collapse;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:24px">'
            . '<tr><td style="padding:8px 12px;color:#6b7280;width:120px">Name</td><td style="padding:8px 12px"><strong>' . esc_html( $client_name ) . '</strong></td></tr>'
            . '<tr><td style="padding:8px 12px;color:#6b7280">Phone</td><td style="padding:8px 12px">' . esc_html( $inquiry['phone'] ) . '</td></tr>'
            . $pet_line
            . $dates_line
            . '</table>'
            . '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;padding:16px;margin-bottom:24px;">'
            . '<p style="margin:0 0 8px;color:#1e40af;font-weight:600">What happens next?</p>'
            . '<ol style="margin:0;padding-left:20px;color:#374151;line-height:1.8">'
            . '<li>Our team reviews your inquiry (usually within 1 business day).</li>'
            . '<li>We send you a personalised onboarding link to complete your pet\'s profile.</li>'
            . '<li>Once reviewed, we confirm availability and finalise your booking.</li>'
            . '</ol>'
            . '</div>'
            . '<p style="color:#374151;margin:0 0 4px">If you have any immediate questions, feel free to reply to this email or contact us directly.</p>'
            . '<p style="color:#374151;margin:0">We look forward to welcoming your pet! 🐾</p>'
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $facility . ' <' . get_option( 'admin_email' ) . '>',
            'Reply-To: ' . get_option( 'admin_email' ),
        ];

        wp_mail( $email, $subject, $body, $headers );
    }

    /**
     * Customer completed onboarding + accepted T&C. Status just moved to READY_FOR_REVIEW.
     *
     * @param array $inquiry    Row from opb_inquiries.
     * @param array $ob_client  Row from opb_onboarding_clients (may have defaults).
     */
    public static function notify_onboarding_complete( array $inquiry, array $ob_client ): void {
        $facility  = self::facility_name();
        $client_name = $ob_client['name'] ?? $inquiry['owner_name'];
        $subject   = "[{$facility}] Onboarding Complete — {$client_name} is ready for review";
        $admin_url = admin_url( 'admin.php?page=opb-admin#/inquiries/' . $inquiry['id'] );

        $pet_count_row = $ob_client['pet_count'] ?? null;

        $body = self::wrap_html( $subject,
            '<p style="color:#374151;margin:0 0 20px">'
            . '<strong>' . esc_html( $client_name ) . '</strong> has completed their onboarding form and accepted the Terms &amp; Conditions.'
            . ' This inquiry is now <strong>Ready for Review</strong>.</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:24px">'
            . '<tr><td style="padding:4px 0;color:#6b7280;width:140px">Name</td><td style="padding:4px 0"><strong>' . esc_html( $client_name ) . '</strong></td></tr>'
            . '<tr><td style="padding:4px 0;color:#6b7280">Phone</td><td style="padding:4px 0">' . esc_html( $inquiry['phone'] ) . '</td></tr>'
            . ( $inquiry['email'] ? '<tr><td style="padding:4px 0;color:#6b7280">Email</td><td style="padding:4px 0">' . esc_html( $inquiry['email'] ) . '</td></tr>' : '' )
            . '<tr><td style="padding:4px 0;color:#6b7280">T&amp;C Accepted</td><td style="padding:4px 0;color:#16a34a">✓ Yes (v' . esc_html( $ob_client['tc_version'] ?? OPB_Onboarding_Handler::TC_VERSION ) . ')</td></tr>'
            . ( $ob_client['tc_accepted_at'] ? '<tr><td style="padding:4px 0;color:#6b7280">Accepted At</td><td style="padding:4px 0">' . esc_html( $ob_client['tc_accepted_at'] ) . '</td></tr>' : '' )
            . ( $ob_client['address'] ? '<tr><td style="padding:4px 0;color:#6b7280">Address</td><td style="padding:4px 0">' . esc_html( $ob_client['address'] ) . '</td></tr>' : '' )
            . '</table>'
            . '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:12px 16px;margin-bottom:24px;color:#166534;">'
            . 'Review their pet details, uploaded documents, and then use <strong>Convert to Client</strong> to create their records.'
            . '</div>'
            . '<p style="margin:0"><a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#15803d;color:#fff;text-decoration:none;padding:10px 20px;border-radius:6px;font-weight:600">Review &amp; Convert →</a></p>'
        );

        self::send( self::recipients( $inquiry['branch_id'] ?? null ), $subject, $body );
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Resolve notification recipients for a given branch.
     * Always includes the site admin email; adds branch manager email when resolvable.
     *
     * @return string[]
     */
    private static function recipients( ?int $branch_id ): array {
        $emails = [];

        // 1. WordPress site admin
        $admin_email = get_option( 'admin_email' );
        if ( $admin_email ) {
            $emails[] = $admin_email;
        }

        // 2. Any user with opb_super_admin role
        $super_admins = get_users( [ 'role' => 'opb_super_admin', 'fields' => [ 'user_email' ] ] );
        foreach ( $super_admins as $u ) {
            $emails[] = $u->user_email;
        }

        // 3. Branch manager(s) for the specific branch
        if ( $branch_id ) {
            global $wpdb;
            $branch_managers = $wpdb->get_col( $wpdb->prepare(
                "SELECT u.user_email
                 FROM {$wpdb->users} u
                 JOIN {$wpdb->usermeta} um_role ON um_role.user_id = u.ID AND um_role.meta_key = %s AND um_role.meta_value LIKE %s
                 JOIN {$wpdb->usermeta} um_branch ON um_branch.user_id = u.ID AND um_branch.meta_key = 'opb_branch_id' AND um_branch.meta_value = %s
                 WHERE u.user_email != ''",
                $wpdb->get_blog_prefix() . 'capabilities',
                '%opb_branch_manager%',
                (string) $branch_id
            ) );
            foreach ( $branch_managers as $email ) {
                $emails[] = $email;
            }
        }

        // De-duplicate, validate
        $emails = array_values( array_unique(
            array_filter( $emails, fn( $e ) => is_email( $e ) )
        ) );

        return $emails ?: [ get_option( 'admin_email' ) ];
    }

    /**
     * Send the email. Silently no-ops if no valid recipients.
     *
     * @param string[] $to
     */
    private static function send( array $to, string $subject, string $html_body ): void {
        if ( ! $to ) return;

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . self::facility_name() . ' <' . get_option( 'admin_email' ) . '>',
        ];

        wp_mail( $to, $subject, $html_body, $headers );
    }

    private static function facility_name(): string {
        return get_bloginfo( 'name' ) ?: 'Onukonu Pet Boarding';
    }

    /**
     * Wrap content in a clean, minimal HTML email shell.
     */
    private static function wrap_html( string $title, string $content ): string {
        $facility = self::facility_name();
        $year     = gmdate( 'Y' );

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html( $title ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'
            // Header band
            . '<tr><td style="background:#1e3a8a;border-radius:8px 8px 0 0;padding:20px 32px;">'
            . '<span style="color:#fff;font-size:18px;font-weight:700;">' . esc_html( $facility ) . '</span>'
            . '</td></tr>'
            // Body
            . '<tr><td style="background:#fff;padding:32px;border-radius:0 0 8px 8px;">'
            . $content
            . '</td></tr>'
            // Footer
            . '<tr><td style="padding:20px 0;text-align:center;color:#9ca3af;font-size:12px;">'
            . '&copy; ' . $year . ' ' . esc_html( $facility ) . ' &mdash; This is an automated notification.'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }
}
