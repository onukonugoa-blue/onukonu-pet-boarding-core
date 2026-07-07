<?php
/**
 * OPB_Client_Auth
 *
 * Email OTP authentication and session management for the client-facing
 * relationship page (/my-pets/).
 *
 * OTP:     6-digit numeric, bcrypt-hashed, 10-minute TTL, max 5 attempts, single-use.
 * Session: 64-char hex token (32 random bytes), SHA-256 hashed in DB, 24-hour TTL.
 *          Read from: cookie opb_client_session  OR  Authorization: Bearer <token>.
 *
 * Security principles:
 *   - OTP never stored in plain text.
 *   - Session token hash stored, plain token travels only in cookie / response body.
 *   - Email enumeration prevented: request-otp always returns success message.
 *   - All access audited in opb_client_access_log.
 */
class OPB_Client_Auth {

    const COOKIE_NAME   = 'opb_client_session';
    const OTP_TTL       = 600;     // 10 minutes
    const OTP_MAX_TRIES = 5;
    const OTP_RATE_MAX  = 3;       // max requests per OTP_TTL window
    const SESSION_TTL   = 86400;   // 24 hours

    // ── Client lookup ─────────────────────────────────────────────────────────

    /**
     * Find an active client by email address.
     * Returns the client row (id, name, email) or null.
     */
    public static function find_client_by_email( string $email ): ?array {
        global $wpdb;
        if ( ! is_email( $email ) ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, email
             FROM {$wpdb->prefix}opb_clients
             WHERE email = %s AND status = 'active'
             LIMIT 1",
            sanitize_email( $email )
        ), ARRAY_A ) ?: null;
    }

    // ── OTP ───────────────────────────────────────────────────────────────────

    /**
     * Generate and persist a new OTP for the given client.
     * Rate-limited to OTP_RATE_MAX requests per OTP_TTL window.
     *
     * @return string|WP_Error  Plain 6-digit OTP to be emailed, or WP_Error.
     */
    public static function generate_otp( int $client_id, string $email, string $ip ): string|WP_Error {
        global $wpdb;

        // Rate-limit
        $recent = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}opb_client_otps
             WHERE client_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $client_id, self::OTP_TTL
        ) );

        if ( $recent >= self::OTP_RATE_MAX ) {
            return new WP_Error(
                'too_many_requests',
                'Too many verification requests. Please wait a few minutes before trying again.',
                [ 'status' => 429 ]
            );
        }

        // Invalidate any live OTPs for this client
        // Use UTC_TIMESTAMP() because expires_at is stored as UTC via gmdate().
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}opb_client_otps
             SET used_at = UTC_TIMESTAMP()
             WHERE client_id = %d AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()",
            $client_id
        ) );

        $otp      = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
        $otp_hash = password_hash( $otp, PASSWORD_DEFAULT );
        $expires  = gmdate( 'Y-m-d H:i:s', time() + self::OTP_TTL );

        $wpdb->insert(
            "{$wpdb->prefix}opb_client_otps",
            [
                'client_id'     => $client_id,
                'email'         => $email,
                'otp_hash'      => $otp_hash,
                'expires_at'    => $expires,
                'attempt_count' => 0,
                'ip_address'    => $ip,
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $wpdb->insert_id ) {
            return new WP_Error( 'db_error', 'Could not generate verification code.', [ 'status' => 500 ] );
        }

        self::log( $client_id, 'otp_sent', $ip, '' );

        return $otp;
    }

    /**
     * Verify a submitted OTP for the given email.
     *
     * @return int|WP_Error  client_id on success, WP_Error on failure.
     */
    public static function verify_otp( string $email, string $otp_plain, string $ip ): int|WP_Error {
        global $wpdb;

        $client = self::find_client_by_email( $email );
        if ( ! $client ) {
            return new WP_Error( 'not_found', 'No account found with that email address.', [ 'status' => 404 ] );
        }

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}opb_client_otps
             WHERE client_id = %d AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY id DESC LIMIT 1",
            $client['id']
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_Error(
                'otp_expired',
                'Your code has expired or was already used. Please request a new one.',
                [ 'status' => 410 ]
            );
        }

        if ( (int) $row['attempt_count'] >= self::OTP_MAX_TRIES ) {
            return new WP_Error(
                'too_many_attempts',
                'Too many incorrect attempts. Please request a new code.',
                [ 'status' => 429 ]
            );
        }

        // Increment attempt count before verifying (prevents timing attacks)
        $wpdb->update(
            "{$wpdb->prefix}opb_client_otps",
            [ 'attempt_count' => (int) $row['attempt_count'] + 1 ],
            [ 'id'            => (int) $row['id'] ],
            [ '%d' ], [ '%d' ]
        );

        if ( ! password_verify( $otp_plain, $row['otp_hash'] ) ) {
            $remaining = max( 0, self::OTP_MAX_TRIES - (int) $row['attempt_count'] - 1 );
            if ( $remaining > 0 ) {
                $msg = "Incorrect code. {$remaining} attempt" . ( $remaining === 1 ? '' : 's' ) . ' remaining.';
            } else {
                $msg = 'Incorrect code. No attempts remaining. Please request a new code.';
            }
            return new WP_Error( 'otp_invalid', $msg, [ 'status' => 401 ] );
        }

        // Mark OTP as used
        $wpdb->update(
            "{$wpdb->prefix}opb_client_otps",
            [ 'used_at' => current_time( 'mysql' ) ],
            [ 'id'      => (int) $row['id'] ],
            [ '%s' ], [ '%d' ]
        );

        self::log( (int) $client['id'], 'otp_verified', $ip, '' );

        return (int) $client['id'];
    }

    // ── Session ───────────────────────────────────────────────────────────────

    /**
     * Create a new 24-hour session for the given client.
     * Sets an HttpOnly, Secure, SameSite=Lax cookie and returns the plain token.
     */
    public static function create_session( int $client_id, string $ip, string $ua ): string {
        global $wpdb;

        $token      = bin2hex( random_bytes( 32 ) );
        $token_hash = hash( 'sha256', $token );
        $expires    = gmdate( 'Y-m-d H:i:s', time() + self::SESSION_TTL );

        $wpdb->insert(
            "{$wpdb->prefix}opb_client_sessions",
            [
                'client_id'        => $client_id,
                'token_hash'       => $token_hash,
                'expires_at'       => $expires,
                'last_accessed_at' => current_time( 'mysql' ),
                'ip_address'       => $ip,
                'user_agent'       => substr( $ua, 0, 255 ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        // Set HttpOnly cookie so JS cannot read it (XSS protection)
        setcookie( self::COOKIE_NAME, $token, [
            'expires'  => time() + self::SESSION_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );

        self::log( $client_id, 'session_created', $ip, '' );

        return $token;
    }

    /**
     * Resolve session token from cookie or Authorization header.
     * Updates last_accessed_at on the session row.
     *
     * @return int|null  client_id, or null if session is missing/invalid/expired.
     */
    public static function get_session_client_id( WP_REST_Request $r ): ?int {
        global $wpdb;

        $token = self::extract_token( $r );
        if ( ! $token ) {
            return null;
        }

        $token_hash = hash( 'sha256', $token );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, client_id
             FROM {$wpdb->prefix}opb_client_sessions
             WHERE token_hash    = %s
               AND invalidated_at IS NULL
               AND expires_at    > UTC_TIMESTAMP()
             LIMIT 1",
            $token_hash
        ), ARRAY_A );

        if ( ! $row ) {
            return null;
        }

        $wpdb->update(
            "{$wpdb->prefix}opb_client_sessions",
            [ 'last_accessed_at' => current_time( 'mysql' ) ],
            [ 'id'               => (int) $row['id'] ],
            [ '%s' ], [ '%d' ]
        );

        return (int) $row['client_id'];
    }

    /**
     * Invalidate the session identified by cookie or Authorization header.
     * Also expires the cookie on the client.
     */
    public static function invalidate_session( WP_REST_Request $r ): void {
        global $wpdb;

        $token = self::extract_token( $r );

        if ( $token ) {
            $token_hash = hash( 'sha256', $token );
            $wpdb->update(
                "{$wpdb->prefix}opb_client_sessions",
                [ 'invalidated_at' => current_time( 'mysql' ) ],
                [ 'token_hash'     => $token_hash ],
                [ '%s' ], [ '%s' ]
            );
        }

        // Expire cookie
        setcookie( self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    // ── Email ─────────────────────────────────────────────────────────────────

    /**
     * Send the OTP verification email to the client.
     */
    public static function send_otp_email( array $client, string $otp ): bool {
        $facility       = OPB_Customizations::facility_name();
        $raw_email      = OPB_Customizations::get( 'facility_email' ) ?: get_bloginfo( 'admin_email' );
        $facility_email = is_email( sanitize_email( $raw_email ) ) ? sanitize_email( $raw_email ) : '';
        $subject        = "Your verification code — {$facility}";

        $body = self::email_wrap(
            $subject,
            '<p style="color:#374151;margin:0 0 20px">Hi <strong>' . esc_html( $client['name'] ) . '</strong>,</p>'
            . '<p style="color:#374151;margin:0 0 24px">Use the code below to access your pet profile on '
            . esc_html( $facility ) . '. This code expires in <strong>10 minutes</strong> and can only be used once.</p>'
            . '<div style="text-align:center;margin:0 0 28px">'
            . '<div style="display:inline-block;background:#f0f9ff;border:2px solid #bae6fd;'
            . 'border-radius:12px;padding:20px 40px">'
            . '<div style="font-size:36px;font-weight:800;letter-spacing:8px;color:#0c4a6e;font-family:monospace">'
            . esc_html( $otp )
            . '</div>'
            . '<div style="font-size:12px;color:#6b7280;margin-top:8px;letter-spacing:0">Verification Code</div>'
            . '</div>'
            . '</div>'
            . '<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:6px;padding:12px 16px;margin-bottom:20px">'
            . '<p style="margin:0;color:#92400e;font-size:13px">🔒 <strong>Never share this code with anyone.</strong> '
            . esc_html( $facility ) . ' staff will never ask for this code.</p>'
            . '</div>'
            . '<p style="color:#6b7280;font-size:13px;margin:0">If you did not request this code, you can safely ignore this email.</p>'
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        if ( $facility_email ) {
            $headers[] = 'From: ' . $facility . ' <' . $facility_email . '>';
        }

        $sent = wp_mail( $client['email'], $subject, $body, $headers );

        if ( ! $sent ) {
            error_log( '[OPB Client Auth] OTP email failed to send to ' . $client['email'] . ' — wp_mail() returned false. Check SMTP configuration.' );
        }

        return $sent;
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public static function log( int $client_id, string $event, string $ip, string $meta ): void {
        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}opb_client_access_log",
            [
                'client_id'  => $client_id ?: null,
                'event'      => $event,
                'ip_address' => $ip,
                'meta'       => $meta,
            ],
            [ '%d', '%s', '%s', '%s' ]
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Extract the raw session token from the request (cookie or Bearer header).
     * Returns null if not found or invalid format.
     */
    private static function extract_token( WP_REST_Request $r ): ?string {
        // 1. HttpOnly cookie
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $t = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            if ( strlen( $t ) === 64 && ctype_xdigit( $t ) ) {
                return $t;
            }
        }

        // 2. Authorization: Bearer <token>
        $auth = $r->get_header( 'authorization' );
        if ( $auth && str_starts_with( $auth, 'Bearer ' ) ) {
            $t = trim( substr( $auth, 7 ) );
            if ( strlen( $t ) === 64 && ctype_xdigit( $t ) ) {
                return $t;
            }
        }

        return null;
    }

    private static function email_wrap( string $title, string $content ): string {
        $facility = OPB_Customizations::facility_name();
        $year     = gmdate( 'Y' );

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<title>' . esc_html( $title ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;'
            . 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'
            . '<tr><td style="background:#1e3a8a;border-radius:8px 8px 0 0;padding:20px 32px">'
            . '<span style="color:#fff;font-size:18px;font-weight:700">🐾 ' . esc_html( $facility ) . '</span>'
            . '</td></tr>'
            . '<tr><td style="background:#fff;padding:32px;border-radius:0 0 8px 8px">'
            . $content
            . '</td></tr>'
            . '<tr><td style="padding:20px 0;text-align:center;color:#9ca3af;font-size:12px">'
            . '&copy; ' . $year . ' ' . esc_html( $facility ) . ' &mdash; This is an automated message.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
