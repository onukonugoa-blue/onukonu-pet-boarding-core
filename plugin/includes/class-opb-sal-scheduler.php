<?php
/**
 * OPB_SAL_Scheduler
 *
 * Situational Awareness Layer — Scheduler (v3.1.0)
 *
 * Manages WP Cron scheduling for the three SAL brief types:
 *   - Morning Operations Brief   (default 07:00)
 *   - Evening Closure Brief      (default 19:00)
 *   - Accounts Snapshot          (default 09:00)
 *
 * APPROACH:
 *   A single hourly WP Cron hook (opb_cron_sal_check) checks whether each
 *   enabled brief should run based on the configured time and whether it has
 *   already been sent today. This avoids complex daily-at-specific-time
 *   scheduling on shared hosting where cron precision is limited.
 *
 * IDEMPOTENCY:
 *   A WP option (opb_sal_sent_today_{type}_{date}) prevents duplicate delivery
 *   within the same calendar day. Sending via the manual "Send Now" button
 *   does NOT write this flag, allowing re-generation from the admin UI.
 *
 * SAFETY GUARANTEE:
 *   All public methods are wrapped in try/catch(\Throwable).
 */
class OPB_SAL_Scheduler {

    const CRON_HOOK = 'opb_cron_sal_check';

    // ── Brief configuration ────────────────────────────────────────────────────

    const BRIEF_TYPES = [
        'morning'  => [ 'default_time' => '07:00', 'label' => 'Morning Operations Brief' ],
        'evening'  => [ 'default_time' => '19:00', 'label' => 'Evening Closure Brief'   ],
        'accounts' => [ 'default_time' => '09:00', 'label' => 'Accounts Snapshot'        ],
    ];

    // ── Cron registration ──────────────────────────────────────────────────────

    /**
     * Register the SAL cron schedule interval.
     * Called from cron_schedules filter.
     */
    public static function add_schedule( array $schedules ): array {
        $schedules['opb_sal_hourly'] = [
            'interval' => HOUR_IN_SECONDS,
            'display'  => 'OPB SAL Check (hourly)',
        ];
        return $schedules;
    }

    /**
     * Ensure the SAL hourly check event is scheduled.
     * Called from init action.
     */
    public static function maybe_schedule(): void {
        try {
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time(), 'opb_sal_hourly', self::CRON_HOOK );
            }
        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] maybe_schedule() error: ' . $e->getMessage() );
        }
    }

    /**
     * Reschedule all SAL cron events (called when config changes).
     */
    public static function reschedule_all(): void {
        try {
            wp_clear_scheduled_hook( self::CRON_HOOK );
            wp_schedule_event( time(), 'opb_sal_hourly', self::CRON_HOOK );
        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] reschedule_all() error: ' . $e->getMessage() );
        }
    }

    // ── Cron handler ───────────────────────────────────────────────────────────

    /**
     * Main cron handler: called every hour.
     * Checks whether each enabled brief is due and runs it.
     */
    public static function check_and_run(): void {
        try {
            if ( OPB_Customizations::get( 'sal_enabled' ) === '0' ) {
                return;
            }

            $now_time = current_time( 'H:i' );
            $today    = current_time( 'Y-m-d' );

            foreach ( self::BRIEF_TYPES as $type => $meta ) {
                $enabled_key = "sal_{$type}_brief_enabled";
                if ( $type === 'accounts' ) {
                    $enabled_key = 'sal_accounts_snapshot_enabled';
                }

                $enabled = OPB_Customizations::get( $enabled_key ) !== '0';
                if ( ! $enabled ) {
                    continue;
                }

                $time_key     = "sal_{$type}_brief_time";
                if ( $type === 'accounts' ) {
                    $time_key = 'sal_accounts_snapshot_time';
                }

                $configured_time = OPB_Customizations::get( $time_key ) ?: $meta['default_time'];

                // Check if the current hour matches the configured hour
                if ( ! self::is_due( $now_time, $configured_time ) ) {
                    continue;
                }

                // Idempotency: skip if already sent today
                $sent_key = "opb_sal_sent_today_{$type}_{$today}";
                if ( get_option( $sent_key ) ) {
                    continue;
                }

                // Run the brief
                $result = self::run_brief( $type, 'scheduled' );
                if ( $result['ok'] ?? false ) {
                    update_option( $sent_key, current_time( 'mysql' ), false );
                }
            }

        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] check_and_run() error: ' . $e->getMessage() );
        }
    }

    // ── Brief runner ───────────────────────────────────────────────────────────

    /**
     * Run a specific brief type: snapshot → format → queue → deliver.
     *
     * @param  string $brief_type  'morning' | 'evening' | 'accounts'
     * @param  string $trigger     'scheduled' | 'manual'
     * @return array  { ok, queue_id, telegram_ok, used_fallback, timing_ms, error? }
     */
    public static function run_brief( string $brief_type, string $trigger = 'manual' ): array {
        $now = current_time( 'mysql' );

        try {
            update_option( "opb_sal_last_run_{$brief_type}", $now, false );

            $chat_id   = OPB_SAL_API::sal_chat_id();
            $bot_token = trim( OPB_Customizations::get( 'telegram_bot_token' ) );

            if ( ! $chat_id || ! $bot_token ) {
                $err = 'SAL Telegram not configured (bot token or chat ID missing).';
                update_option( "opb_sal_last_failure_{$brief_type}", $now, false );
                update_option( "opb_sal_last_error_{$brief_type}",   $err, false );
                self::log_brief( $brief_type, $trigger, false, false, 0, null, '', $err );
                return [ 'ok' => false, 'error' => $err ];
            }

            // 1. Generate snapshot
            $snapshot = OPB_SAL_Snapshot::generate( $brief_type );
            if ( isset( $snapshot['error'] ) ) {
                throw new \RuntimeException( 'Snapshot error: ' . $snapshot['error'] );
            }

            // 2. Format via Gemini (with deterministic fallback)
            $formatted = OPB_SAL_Formatter::format( $snapshot );

            $telegram_message = $formatted['telegram_message'];
            $used_fallback    = $formatted['used_fallback'];
            $timing_ms        = $formatted['timing_ms'] ?? 0;

            // 3. Queue the brief
            $queue_id = self::queue_brief( $brief_type, $telegram_message, $snapshot, $trigger );

            // 4. Deliver immediately via Telegram
            $tg_ok = OPB_Telegram_Consumer::send_telegram_to( $bot_token, $chat_id, $telegram_message );

            // 5. Update queue record with delivery status
            if ( $queue_id ) {
                global $wpdb;
                $table = "{$wpdb->prefix}opb_opsmail_queue";
                if ( $tg_ok ) {
                    $wpdb->update(
                        $table,
                        [
                            'telegram_status'  => 'SENT',
                            'telegram_sent_at' => current_time( 'mysql' ),
                        ],
                        [ 'id' => $queue_id ],
                        [ '%s', '%s' ],
                        [ '%d' ]
                    );
                } else {
                    $wpdb->update(
                        $table,
                        [ 'telegram_status' => 'FAILED' ],
                        [ 'id' => $queue_id ],
                        [ '%s' ],
                        [ '%d' ]
                    );
                }
            }

            if ( $tg_ok ) {
                update_option( "opb_sal_last_success_{$brief_type}", $now, false );
                update_option( "opb_sal_last_error_{$brief_type}",   '',  false );
            } else {
                update_option( "opb_sal_last_failure_{$brief_type}", $now, false );
                update_option( "opb_sal_last_error_{$brief_type}",   'Telegram delivery failed.', false );
            }

            self::log_brief( $brief_type, $trigger, $tg_ok, $used_fallback, $timing_ms, $queue_id ?: null, $telegram_message, $tg_ok ? '' : 'Telegram delivery failed.' );

            return [
                'ok'           => true,
                'queue_id'     => $queue_id,
                'telegram_ok'  => $tg_ok,
                'used_fallback'=> $used_fallback,
                'timing_ms'    => $timing_ms,
            ];

        } catch ( \Throwable $e ) {
            $err = $e->getMessage();
            error_log( "[OPB SAL] run_brief({$brief_type}) error: {$err}" );
            update_option( "opb_sal_last_failure_{$brief_type}", $now, false );
            update_option( "opb_sal_last_error_{$brief_type}",   $err, false );
            self::log_brief( $brief_type, $trigger, false, false, 0, null, '', $err );
            return [ 'ok' => false, 'error' => $err ];
        }
    }

    // ── History logger ─────────────────────────────────────────────────────────

    /**
     * Insert one row into opb_sal_brief_history for every delivery attempt.
     */
    private static function log_brief(
        string  $brief_type,
        string  $trigger,
        bool    $telegram_ok,
        bool    $used_fallback,
        int     $timing_ms,
        ?int    $queue_id,
        string  $message_text,
        string  $error
    ): void {
        global $wpdb;
        $table = "{$wpdb->prefix}opb_sal_brief_history";
        $wpdb->insert(
            $table,
            [
                'brief_type'    => $brief_type,
                'trigger_type'  => $trigger,
                'sent_at'       => current_time( 'mysql' ),
                'telegram_ok'   => $telegram_ok   ? 1 : 0,
                'used_fallback' => $used_fallback  ? 1 : 0,
                'timing_ms'     => $timing_ms,
                'queue_id'      => $queue_id,
                'message_text'  => $message_text !== '' ? $message_text : null,
                'error'         => $error        !== '' ? substr( $error, 0, 500 ) : null,
            ],
            [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
        );
    }

    // ── Queue insertion ────────────────────────────────────────────────────────

    /**
     * Insert a SAL brief into the OPSMAIL queue for audit trail.
     * Returns the inserted row ID or 0 on failure.
     */
    private static function queue_brief(
        string $brief_type,
        string $telegram_message,
        array  $snapshot,
        string $trigger
    ): int {
        try {
            global $wpdb;
            $table      = "{$wpdb->prefix}opb_opsmail_queue";
            $event_uuid = wp_generate_uuid4();
            $now        = current_time( 'mysql' );

            $event_map = [
                'morning'  => 'SAL.MORNING_BRIEF',
                'evening'  => 'SAL.EVENING_BRIEF',
                'accounts' => 'SAL.ACCOUNTS_SNAPSHOT',
            ];
            $event_type = $event_map[ $brief_type ] ?? 'SAL.BRIEF';

            $subject_map = [
                'morning'  => 'Morning Operations Brief',
                'evening'  => 'Evening Closure Brief',
                'accounts' => 'Accounts Snapshot',
            ];
            $subject = 'SAL: ' . ( $subject_map[ $brief_type ] ?? 'Brief' ) . ' — ' . ( $snapshot['date'] ?? '' );

            $payload = wp_json_encode( [
                'brief_type'    => $brief_type,
                'trigger'       => $trigger,
                'date'          => $snapshot['date']       ?? '',
                'totals'        => $snapshot['totals']     ?? [],
                'target_chat_id'=> OPB_SAL_API::sal_chat_id(),
                'telegram_message' => mb_substr( $telegram_message, 0, 2000 ),
            ], JSON_UNESCAPED_UNICODE );

            $wpdb->insert( $table, [
                'event_uuid'        => $event_uuid,
                'event_type'        => $event_type,
                'source_system'     => 'SAL',
                'entity_type'       => 'BRIEF',
                'entity_id'         => null,
                'branch_id'         => null,
                'user_id'           => get_current_user_id() ?: null,
                'origin_type'       => 'SYSTEM',
                'priority'          => 'NORMAL',
                'subject'           => mb_substr( $subject, 0, 250 ),
                'summary'           => mb_substr( $telegram_message, 0, 500 ),
                'payload_json'      => $payload,
                'content_hash'      => null,
                'recipient_email'   => null,
                'mail_status'       => 'ACKNOWLEDGED',
                'telegram_status'   => 'PENDING',
                'mail_attempts'     => 0,
                'telegram_attempts' => 0,
                'created_at'        => $now,
            ] );

            return (int) $wpdb->insert_id;

        } catch ( \Throwable $e ) {
            error_log( '[OPB SAL] queue_brief() error: ' . $e->getMessage() );
            return 0;
        }
    }

    // ── Next run time ──────────────────────────────────────────────────────────

    /**
     * Return the next scheduled run time for a brief type, as a local datetime string.
     */
    public static function next_run_time( string $brief_type ): ?string {
        try {
            $time_key = match( $brief_type ) {
                'morning'  => 'sal_morning_brief_time',
                'evening'  => 'sal_evening_brief_time',
                'accounts' => 'sal_accounts_snapshot_time',
                default    => null,
            };

            if ( ! $time_key ) return null;

            $default_time = self::BRIEF_TYPES[ $brief_type ]['default_time'] ?? '07:00';
            $configured   = OPB_Customizations::get( $time_key ) ?: $default_time;

            // Parse HH:MM
            $parts = explode( ':', $configured );
            $h     = (int) ( $parts[0] ?? 7 );
            $m     = (int) ( $parts[1] ?? 0 );

            $today    = current_time( 'Y-m-d' );
            $now_ts   = current_time( 'timestamp' );
            $sched_ts = strtotime( "{$today} {$h}:{$m}:00" );

            // If already past today, show tomorrow
            if ( $sched_ts < $now_ts ) {
                $tomorrow = date( 'Y-m-d', strtotime( '+1 day', $now_ts ) );
                $sched_ts = strtotime( "{$tomorrow} {$h}:{$m}:00" );
            }

            return date( 'Y-m-d H:i:s', $sched_ts );

        } catch ( \Throwable $e ) {
            return null;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Check whether the current time (HH:MM) is within the scheduled window.
     * Triggers when current hour:minute >= configured time and current hour == configured hour.
     * This means the brief fires once per day in the scheduled hour.
     */
    private static function is_due( string $current_hm, string $configured_hm ): bool {
        $parts_now  = explode( ':', $current_hm );
        $parts_conf = explode( ':', $configured_hm );

        $now_h  = (int) ( $parts_now[0]  ?? 0 );
        $conf_h = (int) ( $parts_conf[0] ?? 0 );

        return $now_h === $conf_h;
    }
}
