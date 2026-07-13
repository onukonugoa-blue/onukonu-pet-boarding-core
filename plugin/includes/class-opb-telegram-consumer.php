<?php
/**
 * OPB_Telegram_Consumer
 *
 * OPSMAIL Telegram Delivery Engine — OPB v3.0.0
 *
 * Reads pending entries from opb_opsmail_queue and delivers them to the
 * configured Telegram Operations Group via the Telegram Bot API.
 *
 * SAFETY GUARANTEE:
 *   Every public method is wrapped in try/catch(\Throwable).
 *   This class will NEVER throw, NEVER block, and NEVER break business workflows.
 *
 * IDEMPOTENCY:
 *   telegram_status = 'SENT' is checked before every delivery attempt.
 *   A single queue entry will never generate more than one Telegram message.
 *
 * DELIVERY CHANNELS:
 *   source_system = 'OPB'          → format_structured()  (rich payload_json fields)
 *   source_system = 'HUMAN_EMAIL'  → format_unstructured() (Gemini summary + classification)
 */
class OPB_Telegram_Consumer {

    const MAX_ATTEMPTS = 3;

    // ── Queue processor ────────────────────────────────────────────────────────

    /**
     * Process up to $limit pending Telegram queue entries.
     * HIGH priority entries are delivered first.
     *
     * @param  int   $limit  Max entries to process per run (default 50).
     * @return array         Log entries — one per processed row.
     */
    public static function process_queue( int $limit = 50 ): array {
        $log = [];
        try {
            if ( ! self::is_configured() ) {
                return [ [ 'status' => 'skip', 'reason' => 'Telegram not configured (token or chat_id missing)' ] ];
            }

            global $wpdb;
            $table = "{$wpdb->prefix}opb_opsmail_queue";

            $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( ! $table_exists ) {
                return [ [ 'status' => 'skip', 'reason' => 'Queue table not found' ] ];
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE telegram_status IN ('PENDING', 'FAILED')
                   AND telegram_attempts < %d
                 ORDER BY FIELD(priority, 'HIGH', 'NORMAL') ASC, id ASC
                 LIMIT %d",
                self::MAX_ATTEMPTS,
                $limit
            ), ARRAY_A );

            if ( ! $rows ) {
                return [ [ 'status' => 'ok', 'reason' => 'No pending Telegram entries', 'delivered' => 0 ] ];
            }

            $delivered = 0;
            foreach ( $rows as $row ) {
                $ok     = self::deliver_event( $row );
                $log[]  = [
                    'id'         => (int) $row['id'],
                    'event_uuid' => $row['event_uuid'],
                    'event_type' => $row['event_type'],
                    'status'     => $ok ? 'sent' : 'failed',
                ];
                if ( $ok ) {
                    $delivered++;
                }
            }

            $log[] = [ 'status' => 'ok', 'delivered' => $delivered, 'total' => count( $rows ) ];

        } catch ( \Throwable $e ) {
            error_log( '[OPB TELEGRAM] process_queue error: ' . $e->getMessage() );
            $log[] = [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
        return $log;
    }

    // ── Single-event delivery ──────────────────────────────────────────────────

    /**
     * Deliver one queue row to Telegram.
     *
     * @param  array $row  Full row from opb_opsmail_queue (ARRAY_A).
     * @return bool        true on success, false on failure.
     */
    public static function deliver_event( array $row ): bool {
        try {
            global $wpdb;
            $table = "{$wpdb->prefix}opb_opsmail_queue";
            $id    = (int) $row['id'];

            // ── Idempotency guard ──────────────────────────────────────────────
            // Re-read current status from DB — another process may have already
            // delivered this event between when the batch was fetched and now.
            $current_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT telegram_status FROM {$table} WHERE id = %d",
                $id
            ) );
            if ( $current_status === 'SENT' ) {
                return true;
            }

            // Increment attempt counter before sending
            $attempts = (int) ( $row['telegram_attempts'] ?? 0 ) + 1;
            $wpdb->update(
                $table,
                [ 'telegram_attempts' => $attempts ],
                [ 'id' => $id ],
                [ '%d' ],
                [ '%d' ]
            );

            $text = self::format_message( $row );
            $ok   = self::send_telegram( $text );

            if ( $ok ) {
                $wpdb->update(
                    $table,
                    [
                        'telegram_status'  => 'SENT',
                        'telegram_sent_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            } else {
                $wpdb->update(
                    $table,
                    [ 'telegram_status' => 'FAILED' ],
                    [ 'id' => $id ],
                    [ '%s' ],
                    [ '%d' ]
                );
            }

            return $ok;

        } catch ( \Throwable $e ) {
            error_log( '[OPB TELEGRAM] deliver_event(id=' . ( $row['id'] ?? '?' ) . ') error: ' . $e->getMessage() );
            return false;
        }
    }

    // ── Message formatting ─────────────────────────────────────────────────────

    private static function format_message( array $row ): string {
        $source = $row['source_system'] ?? OPB_Opsmail::SOURCE_OPB;
        if ( $source === OPB_Opsmail::SOURCE_HUMAN_EMAIL ) {
            return self::format_unstructured( $row );
        }
        return self::format_structured( $row );
    }

    /**
     * Format a structured OPB event using payload_json fields.
     * Rich detail lines are chosen by event_type.
     */
    private static function format_structured( array $row ): string {
        $event_type = $row['event_type'] ?? '';
        $subject    = $row['subject']    ?? '';
        $summary    = $row['summary']    ?? '';
        $priority   = $row['priority']   ?? 'NORMAL';
        $uuid_short = substr( $row['event_uuid'] ?? '', 0, 8 );

        $payload = [];
        if ( ! empty( $row['payload_json'] ) ) {
            $payload = json_decode( $row['payload_json'], true ) ?? [];
        }

        $emoji = self::event_emoji( $event_type );
        $lines = [];

        // Header
        $lines[] = $emoji . ' <b>' . self::esc( $subject ) . '</b>';
        if ( $summary ) {
            $lines[] = self::esc( $summary );
        }

        // Event-specific detail lines
        if ( str_contains( $event_type, 'BOOKING' ) ) {
            if ( ! empty( $payload['pet_names'] ) ) {
                $lines[] = '🐾 Pet(s): ' . self::esc( $payload['pet_names'] );
            }
            if ( ! empty( $payload['client_name'] ) ) {
                $lines[] = '👤 Owner: ' . self::esc( $payload['client_name'] );
            }
            if ( ! empty( $payload['branch_name'] ) ) {
                $lines[] = '📍 Branch: ' . self::esc( $payload['branch_name'] );
            }
            if ( ! empty( $payload['checkin_date'] ) && ! empty( $payload['checkout_date'] ) ) {
                $lines[] = '📅 ' . self::esc( $payload['checkin_date'] )
                         . ' → ' . self::esc( $payload['checkout_date'] );
            }
            if ( ! empty( $payload['created_by_name'] ) ) {
                $lines[] = '✏️ Created by ' . self::esc( $payload['created_by_name'] );
            }
            if ( ! empty( $payload['booking_value'] ) ) {
                $lines[] = '💰 ₹' . number_format( (float) $payload['booking_value'], 2 );
            }

        } elseif ( str_contains( $event_type, 'EXPENSE' ) ) {
            if ( isset( $payload['amount'] ) ) {
                $lines[] = '💰 Amount: ₹' . number_format( (float) $payload['amount'], 2 );
            }
            if ( ! empty( $payload['category'] ) ) {
                $lines[] = '🏷️ Category: ' . self::esc( $payload['category'] );
            }
            if ( ! empty( $payload['description'] ) ) {
                $lines[] = '📝 ' . self::esc( mb_substr( $payload['description'], 0, 80 ) );
            }
            if ( ! empty( $payload['branch'] ) ) {
                $lines[] = '📍 Branch: ' . self::esc( $payload['branch'] );
            }

        } elseif ( str_contains( $event_type, 'TASK' ) ) {
            if ( ! empty( $payload['title'] ) ) {
                $lines[] = '📋 Task: ' . self::esc( $payload['title'] );
            }
            if ( ! empty( $payload['assignee'] ) ) {
                $lines[] = '👤 Assignee: ' . self::esc( $payload['assignee'] );
            }
            if ( ! empty( $payload['due_date'] ) ) {
                $lines[] = '📅 Due: ' . self::esc( $payload['due_date'] );
            }

        } elseif ( str_contains( $event_type, 'INQUIRY' ) ) {
            if ( ! empty( $payload['owner_name'] ) ) {
                $lines[] = '👤 ' . self::esc( $payload['owner_name'] );
            }
            if ( ! empty( $payload['pet_name'] ) ) {
                $lines[] = '🐾 Pet: ' . self::esc( $payload['pet_name'] );
            }
            if ( ! empty( $payload['phone'] ) ) {
                $lines[] = '📞 ' . self::esc( $payload['phone'] );
            }

        } elseif ( str_contains( $event_type, 'CLIENT' ) ) {
            if ( ! empty( $payload['owner_name'] ) ) {
                $lines[] = '👤 ' . self::esc( $payload['owner_name'] );
            }
            if ( ! empty( $payload['phone'] ) ) {
                $lines[] = '📞 ' . self::esc( $payload['phone'] );
            }
        }

        // Footer
        $lines[] = '';
        $footer  = [];
        if ( $priority === 'HIGH' ) {
            $footer[] = '🔴 HIGH';
        }
        $footer[] = '<code>' . self::esc( $event_type ) . '</code>';
        $footer[] = '<i>#' . self::esc( $uuid_short ) . '</i>';
        $lines[]  = implode( ' · ', $footer );

        return implode( "\n", $lines );
    }

    /**
     * Format a Gemini-classified unstructured email event.
     */
    private static function format_unstructured( array $row ): string {
        $summary        = $row['summary']        ?? '';
        $classification = $row['classification'] ?? '';
        $confidence     = $row['confidence'] !== null ? (float) $row['confidence'] : null;
        $uuid_short     = substr( $row['event_uuid'] ?? '', 0, 8 );

        $payload = [];
        if ( ! empty( $row['payload_json'] ) ) {
            $payload = json_decode( $row['payload_json'], true ) ?? [];
        }

        $sender  = $payload['sender']  ?? '';
        $subject = $payload['subject'] ?? ( $row['subject'] ?? '' );

        $lines   = [];
        $lines[] = '📩 <b>' . self::esc( $classification ?: 'Inbound Email' ) . '</b>';

        if ( $subject ) {
            $lines[] = '<i>' . self::esc( mb_substr( $subject, 0, 120 ) ) . '</i>';
        }
        if ( $summary ) {
            $lines[] = self::esc( $summary );
        }
        if ( $sender ) {
            $lines[] = 'From: <code>' . self::esc( $sender ) . '</code>';
        }
        if ( $confidence !== null ) {
            $lines[] = 'Confidence: ' . round( $confidence * 100 ) . '%';
        }

        $lines[] = '';
        $lines[] = '<code>HUMAN_EMAIL</code> · <i>#' . self::esc( $uuid_short ) . '</i>';

        return implode( "\n", $lines );
    }

    // ── Telegram API ───────────────────────────────────────────────────────────

    /**
     * Send a text message to the configured Telegram chat.
     * Uses HTML parse_mode — callers must use self::esc() for user content.
     *
     * @param  string $text  HTML-formatted message (Telegram HTML subset).
     * @return bool
     */
    public static function send_telegram( string $text ): bool {
        try {
            $token   = self::bot_token();
            $chat_id = self::chat_id();

            if ( ! $token || ! $chat_id ) {
                error_log( '[OPB TELEGRAM] send_telegram: token or chat_id not configured' );
                return false;
            }

            $response = wp_remote_post(
                'https://api.telegram.org/bot' . $token . '/sendMessage',
                [
                    'timeout' => 15,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( [
                        'chat_id'    => $chat_id,
                        'text'       => $text,
                        'parse_mode' => 'HTML',
                    ] ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                error_log( '[OPB TELEGRAM] wp_remote_post error: ' . $response->get_error_message() );
                return false;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                error_log( '[OPB TELEGRAM] API returned HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
                return false;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return ! empty( $body['ok'] );

        } catch ( \Throwable $e ) {
            error_log( '[OPB TELEGRAM] send_telegram exception: ' . $e->getMessage() );
            return false;
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function event_emoji( string $event_type ): string {
        if ( str_contains( $event_type, 'BOOKING' ) ) return '🐾';
        if ( str_contains( $event_type, 'EXPENSE' ) ) return '💰';
        if ( str_contains( $event_type, 'TASK' )    ) return '📋';
        if ( str_contains( $event_type, 'INQUIRY' ) ) return '📨';
        if ( str_contains( $event_type, 'CLIENT' )  ) return '👤';
        if ( str_contains( $event_type, 'ERROR' )   ) return '🚨';
        if ( str_contains( $event_type, 'SYSTEM' )  ) return '⚙️';
        return '📌';
    }

    /** Escape a string for Telegram HTML mode. */
    private static function esc( string $s ): string {
        return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    // ── Explicit-target send ───────────────────────────────────────────────────

    /**
     * Send a Telegram message to an explicitly specified chat ID.
     * Used by the SAL to deliver to the configured SAL reporting destination
     * rather than the default operations chat.
     *
     * @param  string $bot_token  Telegram bot token.
     * @param  string $chat_id    Target chat/group ID.
     * @param  string $text       HTML-formatted message (Telegram HTML subset).
     * @return bool
     */
    public static function send_telegram_to( string $bot_token, string $chat_id, string $text ): bool {
        try {
            if ( ! $bot_token || ! $chat_id ) {
                error_log( '[OPB TELEGRAM] send_telegram_to: token or chat_id empty' );
                return false;
            }

            $response = wp_remote_post(
                'https://api.telegram.org/bot' . $bot_token . '/sendMessage',
                [
                    'timeout' => 15,
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( [
                        'chat_id'    => $chat_id,
                        'text'       => $text,
                        'parse_mode' => 'HTML',
                    ] ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                error_log( '[OPB TELEGRAM] send_telegram_to wp_remote_post error: ' . $response->get_error_message() );
                return false;
            }

            $code     = (int) wp_remote_retrieve_response_code( $response );
            $raw_body = wp_remote_retrieve_body( $response );

            if ( $code !== 200 ) {
                $tg = json_decode( $raw_body, true );
                $desc = $tg['description'] ?? $raw_body;
                error_log( '[OPB TELEGRAM] send_telegram_to HTTP ' . $code . ': ' . $desc );
                return false;
            }

            $body = json_decode( $raw_body, true );
            return ! empty( $body['ok'] );

        } catch ( \Throwable $e ) {
            error_log( '[OPB TELEGRAM] send_telegram_to exception: ' . $e->getMessage() );
            return false;
        }
    }

    // ── Settings accessors ─────────────────────────────────────────────────────

    public static function is_configured(): bool {
        try {
            return self::bot_token() !== '' && self::chat_id() !== '';
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public static function bot_token(): string {
        try {
            return trim( OPB_Customizations::get( 'telegram_bot_token' ) );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    public static function chat_id(): string {
        try {
            return trim( OPB_Customizations::get( 'telegram_chat_id' ) );
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}
