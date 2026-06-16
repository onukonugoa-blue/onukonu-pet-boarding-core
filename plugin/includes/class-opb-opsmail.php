<?php
/**
 * OPB_Opsmail
 *
 * OPSMAIL Operational Intelligence Layer — v1.0 (OPB v2.8.0)
 *
 * This class is the sole entry point for all OPSMAIL operations:
 *   - Appending events to opb_opsmail_queue
 *   - Emitting operational emails via the existing SMTP transport
 *   - Reading OPSMAIL settings from opb_customizations
 *
 * SAFETY GUARANTEE:
 *   Every public method is wrapped in try/catch(\Throwable).
 *   OPSMAIL will NEVER throw, NEVER block, and NEVER break business workflows.
 *
 * ZERO REGRESSION:
 *   This class does NOT intercept, modify, or wrap any existing email path.
 *   It only appends to the queue and emits to the configured OPSMAIL inbox.
 */
class OPB_Opsmail {

    // ── Event taxonomy ─────────────────────────────────────────────────────────

    const EVENT_TYPES = [
        'INQUIRY.RECEIVED',
        'CLIENT.ONBOARDING_RECEIVED',
        'BOOKING.REQUEST_RECEIVED',
        'BOOKING.CONFIRMED',
        'BOOKING.MODIFICATION_REQUESTED',
        'BOOKING.CANCELLED',
        'SUPPORT.REQUEST_RECEIVED',
        'PAYMENT.ISSUE_REPORTED',
        'EXPENSE.LARGE_RECORDED',
        'TASK.CREATED',
        'SYSTEM.ERROR',
    ];

    const ORIGIN_SYSTEM        = 'SYSTEM';
    const ORIGIN_TRUSTED       = 'TRUSTED_MAILBOX';

    const STATUS_PENDING       = 'PENDING';
    const STATUS_SENT          = 'SENT';
    const STATUS_FAILED        = 'FAILED';
    const STATUS_ACKNOWLEDGED  = 'ACKNOWLEDGED';

    // ── Public event push helpers ──────────────────────────────────────────────

    /**
     * INQUIRY.RECEIVED — fired after a public inquiry is successfully stored.
     *
     * @param array $inquiry  Inquiry row (must contain id).
     */
    public static function push_inquiry_received( array $inquiry ): void {
        self::push_event(
            'INQUIRY.RECEIVED',
            'INQUIRY',
            (int) ( $inquiry['id'] ?? 0 ),
            (int) ( $inquiry['branch_id'] ?? 0 ) ?: null,
            'New Inquiry from ' . ( $inquiry['owner_name'] ?? 'Unknown' ),
            'A new boarding inquiry was submitted via the public form.',
            [
                'owner_name' => $inquiry['owner_name'] ?? '',
                'phone'      => $inquiry['phone'] ?? '',
                'pet_name'   => $inquiry['pet_name'] ?? '',
                'pet_type'   => $inquiry['pet_type'] ?? '',
                'source'     => $inquiry['source'] ?? 'web_form',
            ]
        );
    }

    /**
     * CLIENT.ONBOARDING_RECEIVED — fired when a customer completes onboarding
     * and the inquiry advances to READY_FOR_REVIEW.
     *
     * @param array $inquiry    Inquiry row.
     * @param array $ob_client  Onboarding client row.
     */
    public static function push_onboarding_received( array $inquiry, array $ob_client ): void {
        $name = $ob_client['name'] ?? $inquiry['owner_name'] ?? 'Unknown';
        self::push_event(
            'CLIENT.ONBOARDING_RECEIVED',
            'INQUIRY',
            (int) ( $inquiry['id'] ?? 0 ),
            (int) ( $inquiry['branch_id'] ?? 0 ) ?: null,
            'Onboarding completed by ' . $name,
            'A customer completed onboarding and accepted T&C. Ready for staff review.',
            [
                'owner_name' => $name,
                'phone'      => $inquiry['phone'] ?? '',
                'email'      => $inquiry['email'] ?? '',
                'tc_version' => $ob_client['tc_version'] ?? '',
            ]
        );
    }

    /**
     * BOOKING.CONFIRMED — fired after a new booking is successfully created.
     *
     * @param int $booking_id
     * @param int $branch_id
     * @param int $client_id
     */
    public static function push_booking_confirmed( int $booking_id, int $branch_id, int $client_id ): void {
        self::push_event(
            'BOOKING.CONFIRMED',
            'BOOKING',
            $booking_id,
            $branch_id ?: null,
            'New booking #' . $booking_id . ' confirmed',
            'A new boarding booking has been created by staff.',
            [
                'booking_id' => $booking_id,
                'client_id'  => $client_id,
                'branch_id'  => $branch_id,
            ],
            'NORMAL',
            get_current_user_id() ?: null
        );
    }

    /**
     * TASK.CREATED — fired after a new task is inserted.
     *
     * @param int   $task_id
     * @param int   $branch_id
     * @param array $data  Request payload.
     */
    public static function push_task_created( int $task_id, int $branch_id, array $data ): void {
        self::push_event(
            'TASK.CREATED',
            'TASK',
            $task_id,
            $branch_id ?: null,
            'Task #' . $task_id . ': ' . ( $data['title'] ?? 'Untitled' ),
            'A new task has been created.',
            [
                'title'      => $data['title'] ?? '',
                'priority'   => $data['priority'] ?? 'Medium',
                'assignee'   => $data['assignee'] ?? '',
                'due_date'   => $data['due_date'] ?? '',
            ],
            strtoupper( $data['priority'] ?? 'NORMAL' ) === 'HIGH' ? 'HIGH' : 'NORMAL',
            get_current_user_id() ?: null
        );
    }

    /**
     * EXPENSE.LARGE_RECORDED — fired after an expense is created whose amount
     * equals or exceeds the configured threshold.
     *
     * @param array $row  Expense row (must contain id, amount, branch_id, description).
     */
    public static function push_expense_if_large( array $row ): void {
        try {
            $threshold = (float) ( OPB_Customizations::get( 'opsmail_expense_threshold' ) ?: '5000' );
            $amount    = (float) ( $row['amount'] ?? 0 );
            if ( $threshold > 0 && $amount < $threshold ) {
                return;
            }
        } catch ( \Throwable $e ) {
            return;
        }

        self::push_event(
            'EXPENSE.LARGE_RECORDED',
            'EXPENSE',
            (int) ( $row['id'] ?? 0 ),
            (int) ( $row['branch_id'] ?? 0 ) ?: null,
            'Large expense: \u20b9' . number_format( (float) ( $row['amount'] ?? 0 ), 2 )
                . ' — ' . ( $row['description'] ?? '' ),
            'A large expense has been recorded and may require review.',
            [
                'description' => $row['description'] ?? '',
                'amount'      => (float) ( $row['amount'] ?? 0 ),
                'category'    => $row['category'] ?? '',
                'branch'      => $row['branch_name'] ?? '',
                'mode'        => $row['mode'] ?? '',
            ],
            'HIGH',
            get_current_user_id() ?: null
        );
    }

    // ── Core engine ────────────────────────────────────────────────────────────

    /**
     * Append an event to the queue and, when OPSMAIL is enabled, attempt email emission.
     *
     * This method will NEVER throw. Any failure is caught and silently logged.
     */
    private static function push_event(
        string $event_type,
        string $entity_type,
        int    $entity_id,
        ?int   $branch_id,
        string $subject,
        string $summary,
        array  $payload,
        string $priority  = 'NORMAL',
        ?int   $user_id   = null
    ): void {
        try {
            global $wpdb;
            $table = "{$wpdb->prefix}opb_opsmail_queue";

            $table_exists = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
            );
            if ( ! $table_exists ) {
                return;
            }

            $event_uuid  = wp_generate_uuid4();
            $inbox_email = self::inbox_email();
            $now         = current_time( 'mysql' );

            $wpdb->insert( $table, [
                'event_uuid'      => $event_uuid,
                'event_type'      => $event_type,
                'entity_type'     => $entity_type,
                'entity_id'       => $entity_id ?: null,
                'branch_id'       => $branch_id,
                'user_id'         => $user_id ?? ( get_current_user_id() ?: null ),
                'origin_type'     => self::ORIGIN_SYSTEM,
                'priority'        => $priority,
                'subject'         => mb_substr( $subject, 0, 250 ),
                'summary'         => $summary,
                'payload_json'    => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'recipient_email' => $inbox_email ?: null,
                'status'          => self::STATUS_PENDING,
                'mail_attempts'   => 0,
                'created_at'      => $now,
            ] );

            $queue_id = (int) $wpdb->insert_id;
            if ( ! $queue_id ) {
                return;
            }

            if ( ! self::is_enabled() || ! $inbox_email ) {
                return;
            }

            self::emit(
                $queue_id, $event_uuid, $event_type, $entity_type,
                $entity_id, $branch_id, $subject, $summary,
                $payload, $inbox_email, $now
            );

        } catch ( \Throwable $e ) {
            error_log( '[OPB OPSMAIL] push_event(' . $event_type . ') error: ' . $e->getMessage() );
        }
    }

    /**
     * Emit the OPSMAIL email and update queue status.
     * All failures are recorded in last_error; exceptions are caught — never re-thrown.
     */
    private static function emit(
        int    $queue_id,
        string $event_uuid,
        string $event_type,
        string $entity_type,
        int    $entity_id,
        ?int   $branch_id,
        string $subject,
        string $summary,
        array  $payload,
        string $inbox_email,
        string $created_at
    ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}opb_opsmail_queue";

        try {
            $mail_subject = '[OPSMAIL] ' . $event_type . ' #' . $entity_id;

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'X-Ops-Version: 1',
                'X-Ops-Event: ' . $event_type,
                'X-Ops-Entity-Type: ' . $entity_type,
                'X-Ops-Entity-ID: ' . $entity_id,
                'X-Ops-Event-UUID: ' . $event_uuid,
                'X-Ops-Origin: ' . self::ORIGIN_SYSTEM,
            ];

            $body = self::build_email_body(
                $event_type, $entity_type, $entity_id,
                $branch_id, $subject, $summary,
                $payload, $event_uuid, $created_at
            );

            $wpdb->update(
                $table,
                [ 'mail_attempts' => 1 ],
                [ 'id' => $queue_id ],
                [ '%d' ],
                [ '%d' ]
            );

            $sent = wp_mail( $inbox_email, $mail_subject, $body, $headers );

            if ( $sent ) {
                $wpdb->update(
                    $table,
                    [
                        'status'  => self::STATUS_SENT,
                        'sent_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $queue_id ]
                );
            } else {
                $wpdb->update(
                    $table,
                    [
                        'status'     => self::STATUS_FAILED,
                        'last_error' => 'wp_mail() returned false — check SMTP configuration.',
                    ],
                    [ 'id' => $queue_id ]
                );
            }

        } catch ( \Throwable $e ) {
            $wpdb->update(
                $table,
                [
                    'status'     => self::STATUS_FAILED,
                    'last_error' => mb_substr( $e->getMessage(), 0, 500 ),
                ],
                [ 'id' => $queue_id ]
            );
        }
    }

    // ── Email builder ──────────────────────────────────────────────────────────

    private static function build_email_body(
        string $event_type,
        string $entity_type,
        int    $entity_id,
        ?int   $branch_id,
        string $subject,
        string $summary,
        array  $payload,
        string $event_uuid,
        string $created_at
    ): string {
        $facility = OPB_Customizations::facility_name();

        $payload_rows = '';
        foreach ( $payload as $key => $val ) {
            $display_key = ucwords( str_replace( '_', ' ', $key ) );
            $payload_rows .= '<tr>'
                . '<td style="padding:5px 8px;color:#6b7280;white-space:nowrap;width:160px">' . esc_html( $display_key ) . '</td>'
                . '<td style="padding:5px 8px"><strong>' . esc_html( (string) $val ) . '</strong></td>'
                . '</tr>';
        }

        $meta = [
            'event_uuid'  => $event_uuid,
            'event_type'  => $event_type,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'branch_id'   => $branch_id,
            'created_at'  => $created_at,
        ];

        $json_block = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        $badge_colour = '#1e3a8a';
        if ( str_contains( $event_type, 'EXPENSE' ) ) $badge_colour = '#dc2626';
        if ( str_contains( $event_type, 'BOOKING' ) ) $badge_colour = '#166534';
        if ( str_contains( $event_type, 'TASK' )    ) $badge_colour = '#7c3aed';
        if ( str_contains( $event_type, 'INQUIRY' ) ) $badge_colour = '#0369a1';
        if ( str_contains( $event_type, 'ERROR' )   ) $badge_colour = '#dc2626';

        $year = gmdate( 'Y' );

        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html( $subject ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px">'
            . '<tr><td align="center">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%">'

            . '<tr><td style="background:#111827;border-radius:8px 8px 0 0;padding:18px 28px;display:flex;align-items:center">'
            . '<span style="color:#9ca3af;font-size:11px;font-weight:600;letter-spacing:2px;text-transform:uppercase">OPSMAIL</span>'
            . '<span style="color:#6b7280;margin:0 8px">·</span>'
            . '<span style="color:#fff;font-size:14px;font-weight:700">' . esc_html( $facility ) . '</span>'
            . '</td></tr>'

            . '<tr><td style="background:#fff;padding:28px 32px">'

            . '<div style="display:inline-block;background:' . $badge_colour . ';color:#fff;font-size:12px;font-weight:700;'
            . 'padding:4px 12px;border-radius:4px;letter-spacing:1px;margin-bottom:16px">'
            . esc_html( $event_type )
            . '</div>'

            . '<h2 style="margin:0 0 8px;color:#111827;font-size:20px">' . esc_html( $subject ) . '</h2>'
            . '<p style="margin:0 0 24px;color:#6b7280;font-size:14px">' . esc_html( $summary ) . '</p>'

            . '<table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:28px">'
            . '<thead><tr style="background:#f9fafb">'
            . '<th colspan="2" style="padding:8px 12px;text-align:left;font-size:12px;color:#6b7280;font-weight:600;'
            . 'text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid #e5e7eb">Event Details</th>'
            . '</tr></thead>'
            . '<tbody>' . $payload_rows . '</tbody>'
            . '</table>'

            . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;margin-bottom:0">'
            . '<p style="margin:0 0 8px;color:#374151;font-size:12px;font-weight:600;'
            . 'text-transform:uppercase;letter-spacing:1px">Machine-Readable Metadata</p>'
            . '<pre style="margin:0;font-family:\'Menlo\',\'Courier New\',monospace;font-size:12px;'
            . 'color:#374151;white-space:pre-wrap;word-break:break-all">'
            . esc_html( $json_block )
            . '</pre>'
            . '</div>'

            . '</td></tr>'

            . '<tr><td style="padding:16px 0;text-align:center;color:#9ca3af;font-size:11px">'
            . '&copy; ' . $year . ' ' . esc_html( $facility )
            . ' &mdash; OPSMAIL Operational Intelligence &mdash; Do not reply to this message.'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table>'
            . '</body></html>';
    }

    // ── Settings accessors ────────────────────────────────────────────────────

    /**
     * Whether OPSMAIL email emission is active.
     * Queue is always populated regardless of this setting.
     */
    public static function is_enabled(): bool {
        try {
            return OPB_Customizations::get( 'opsmail_enabled' ) === '1';
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Configured OPSMAIL inbox email address.
     * Returns empty string when not configured.
     */
    public static function inbox_email(): string {
        try {
            $email = trim( OPB_Customizations::get( 'opsmail_inbox_email' ) );
            return is_email( $email ) ? $email : '';
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * Trusted origin mailboxes, one per line.
     *
     * @return string[]
     */
    public static function trusted_origins(): array {
        try {
            $raw   = OPB_Customizations::get( 'opsmail_trusted_origins' );
            $lines = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
            return array_values( array_filter( $lines, 'is_email' ) );
        } catch ( \Throwable $e ) {
            return [];
        }
    }

    /**
     * Large-expense threshold in the site's base currency.
     */
    public static function expense_threshold(): float {
        try {
            $v = (float) OPB_Customizations::get( 'opsmail_expense_threshold' );
            return $v > 0 ? $v : 5000.0;
        } catch ( \Throwable $e ) {
            return 5000.0;
        }
    }
}
