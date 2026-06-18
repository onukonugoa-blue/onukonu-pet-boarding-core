<?php
/**
 * OPB_Mailbox_Processor
 *
 * OPSMAIL Mailbox Ingestion Engine — OPB v3.0.0
 *
 * Reads the configured IMAP inbox and routes each message:
 *
 *   X-Ops-Version header present  →  STRUCTURED (OPB-generated OPSMAIL email)
 *                                    SKIP — event is already in the queue.
 *
 *   X-Ops-Version header absent   →  UNSTRUCTURED (human / WooCommerce / support)
 *                                    Classify with Gemini → INSERT queue row
 *                                    → hand off to OPB_Telegram_Consumer.
 *
 * ROUTING RULE (non-negotiable):
 *   Classification is based SOLELY on the presence of the X-Ops-Version header.
 *   Sender address, subject line, and mailbox name play NO part in routing.
 *
 * SAFETY GUARANTEE:
 *   Every public method is wrapped in try/catch(\Throwable).
 *   This class will NEVER throw, NEVER block, and NEVER break business workflows.
 *
 * IDEMPOTENCY:
 *   content_hash = md5(sender:subject:body_excerpt) prevents duplicate queue
 *   entries when the same email is processed more than once.
 *   Processed emails are flagged \Seen immediately after routing.
 */
class OPB_Mailbox_Processor {

    // ── Main entry point ───────────────────────────────────────────────────────

    /**
     * Poll the inbox and process all UNSEEN messages.
     *
     * @return array  Log entries — one per processed message plus a summary.
     */
    public static function process(): array {
        $log = [];
        try {
            if ( ! self::is_configured() ) {
                return [ [
                    'status' => 'skip',
                    'reason' => 'Mailbox processing disabled or IMAP credentials not configured',
                ] ];
            }

            if ( ! extension_loaded( 'imap' ) ) {
                return [ [
                    'status' => 'error',
                    'reason' => 'PHP IMAP extension not available on this server',
                ] ];
            }

            $mailbox = self::connect_imap();
            if ( ! $mailbox ) {
                return [ [
                    'status' => 'error',
                    'reason' => 'Could not connect to IMAP server — check host/port/credentials',
                ] ];
            }

            // Fetch all UNSEEN message UIDs
            $uids = @imap_search( $mailbox, 'UNSEEN', SE_UID );

            if ( ! $uids ) {
                @imap_close( $mailbox );
                return [ [ 'status' => 'ok', 'reason' => 'No new messages', 'processed' => 0 ] ];
            }

            $processed = 0;
            foreach ( $uids as $uid ) {
                try {
                    $entry = self::route_email( $mailbox, (int) $uid );
                    $log[] = $entry;
                } catch ( \Throwable $e ) {
                    $log[] = [
                        'uid'    => $uid,
                        'status' => 'error',
                        'reason' => $e->getMessage(),
                    ];
                } finally {
                    // Mark as SEEN regardless of outcome to prevent reprocessing
                    @imap_setflag_full( $mailbox, (string) $uid, '\\Seen', ST_UID );
                    $processed++;
                }
            }

            @imap_close( $mailbox );
            $log[] = [ 'status' => 'ok', 'processed' => $processed ];

        } catch ( \Throwable $e ) {
            error_log( '[OPB MAILBOX] process() fatal: ' . $e->getMessage() );
            $log[] = [ 'status' => 'error', 'reason' => $e->getMessage() ];
        }
        return $log;
    }

    // ── IMAP connection ────────────────────────────────────────────────────────

    /**
     * Open an IMAP stream to the configured inbox.
     *
     * @return resource|false  IMAP stream or false on failure.
     */
    private static function connect_imap() {
        $host     = OPB_Customizations::get( 'mailbox_imap_host' );
        $port     = (int) OPB_Customizations::get( 'mailbox_imap_port' ) ?: 993;
        $username = OPB_Customizations::get( 'mailbox_imap_username' );
        $password = OPB_Customizations::get( 'mailbox_imap_password' );

        $mailbox_str = '{' . $host . ':' . $port . '/imap/ssl}INBOX';

        // Suppress PHP warnings from failed auth — we handle the false return.
        $conn = @imap_open( $mailbox_str, $username, $password, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ] );

        if ( ! $conn ) {
            $errors = imap_errors() ?: [];
            error_log( '[OPB MAILBOX] IMAP connect failed: ' . implode( '; ', $errors ) );
            return false;
        }

        return $conn;
    }

    // ── Email routing ──────────────────────────────────────────────────────────

    /**
     * Route one message by UID.
     * STRUCTURED  → skip (already in queue via push_event)
     * UNSTRUCTURED → Gemini classify → queue insert → Telegram delivery
     *
     * @param  resource $mailbox  Open IMAP stream.
     * @param  int      $uid      Message UID.
     * @return array              Log entry for this message.
     */
    private static function route_email( $mailbox, int $uid ): array {
        $raw_header = @imap_fetchheader( $mailbox, $uid, FT_UID );
        if ( $raw_header === false ) {
            return [ 'uid' => $uid, 'status' => 'error', 'reason' => 'Could not fetch message headers' ];
        }

        // ── ROUTING RULE ───────────────────────────────────────────────────────
        // X-Ops-Version present = OPB-generated OPSMAIL email.
        // This event was inserted into the queue at emission time by push_event().
        // The Telegram Consumer will pick it up via process_queue().
        // Do NOT re-insert. Do NOT call Gemini. Just skip.
        if ( self::has_ops_header( $raw_header ) ) {
            return [
                'uid'    => $uid,
                'status' => 'skip',
                'reason' => 'OPSMAIL-generated email (X-Ops-Version present) — already in queue',
            ];
        }

        // ── UNSTRUCTURED PATH ──────────────────────────────────────────────────
        $msg_no  = @imap_msgno( $mailbox, $uid );
        $header  = $msg_no ? @imap_headerinfo( $mailbox, $msg_no ) : false;

        $subject = '';
        $sender  = '';

        if ( $header ) {
            if ( ! empty( $header->subject ) ) {
                $subject = @imap_utf8( $header->subject ) ?: $header->subject;
            }
            if ( ! empty( $header->from[0] ) ) {
                $from   = $header->from[0];
                $sender = isset( $from->mailbox, $from->host )
                    ? ( $from->mailbox . '@' . $from->host )
                    : ( $from->mailbox ?? '' );
            }
        }

        $body = self::extract_body( $mailbox, $uid );

        return self::handle_unstructured( $sender, $subject ?: '(no subject)', $body );
    }

    /**
     * Check whether the raw header block contains the OPSMAIL sentinel.
     */
    private static function has_ops_header( string $raw_header ): bool {
        return stripos( $raw_header, 'X-Ops-Version:' ) !== false;
    }

    // ── Body extraction ────────────────────────────────────────────────────────

    /**
     * Extract a plain-text representation of the message body.
     * Prefers text/plain; falls back to stripped text/html.
     * Truncates to 4000 characters for Gemini.
     *
     * @param  resource $mailbox
     * @param  int      $uid
     * @return string
     */
    private static function extract_body( $mailbox, int $uid ): string {
        try {
            $structure = @imap_fetchstructure( $mailbox, $uid, FT_UID );
            if ( ! $structure ) {
                return '';
            }

            $body = '';

            if ( $structure->type === TYPETEXT ) {
                // Single-part message
                $raw      = @imap_fetchbody( $mailbox, $uid, '1', FT_UID ) ?: '';
                $encoding = $structure->encoding ?? ENC7BIT;
                $body     = self::decode_part( $raw, $encoding );
                // Strip HTML if the single part is HTML
                if ( isset( $structure->subtype ) && strtolower( $structure->subtype ) === 'html' ) {
                    $body = strip_tags( $body );
                }
            } elseif ( $structure->type === TYPEMULTIPART && ! empty( $structure->parts ) ) {
                // Multipart: scan parts for text/plain then text/html
                $plain = '';
                $html  = '';
                foreach ( $structure->parts as $idx => $part ) {
                    if ( $part->type !== TYPETEXT ) {
                        continue;
                    }
                    $section  = (string) ( $idx + 1 );
                    $raw      = @imap_fetchbody( $mailbox, $uid, $section, FT_UID ) ?: '';
                    $text     = self::decode_part( $raw, $part->encoding ?? ENC7BIT );
                    $sub_type = strtolower( $part->subtype ?? '' );
                    if ( $sub_type === 'plain' && $plain === '' ) {
                        $plain = $text;
                    } elseif ( $sub_type === 'html' && $html === '' ) {
                        $html = strip_tags( $text );
                    }
                }
                $body = $plain !== '' ? $plain : $html;
            }

            // Fallback: grab raw body
            if ( $body === '' ) {
                $body = strip_tags( @imap_body( $mailbox, $uid, FT_UID ) ?: '' );
            }

            // Sanitise
            $body = html_entity_decode( $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $body = preg_replace( '/[ \t]+/', ' ', $body );
            $body = preg_replace( '/(\r?\n){3,}/', "\n\n", $body );
            $body = trim( $body );

            return mb_substr( $body, 0, 4000 );

        } catch ( \Throwable $e ) {
            error_log( '[OPB MAILBOX] extract_body error: ' . $e->getMessage() );
            return '';
        }
    }

    /**
     * Decode a MIME-encoded body part.
     */
    private static function decode_part( string $raw, int $encoding ): string {
        switch ( $encoding ) {
            case ENCBASE64:          return (string) base64_decode( $raw );
            case ENCQUOTEDPRINTABLE: return (string) quoted_printable_decode( $raw );
            default:                 return $raw;
        }
    }

    // ── Unstructured handler ───────────────────────────────────────────────────

    /**
     * Classify an unstructured email with Gemini, insert a normalised queue
     * entry, and hand off to the Telegram consumer.
     *
     * @param  string $sender   From address.
     * @param  string $subject  Email subject.
     * @param  string $body     Extracted plain-text body (max 4000 chars).
     * @return array            Log entry.
     */
    private static function handle_unstructured(
        string $sender,
        string $subject,
        string $body
    ): array {
        global $wpdb;
        $table = "{$wpdb->prefix}opb_opsmail_queue";

        // ── Duplicate check ────────────────────────────────────────────────────
        $content_hash = md5( $sender . ':' . $subject . ':' . mb_substr( $body, 0, 500 ) );
        $existing_id  = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE content_hash = %s AND origin_type = 'MAILBOX'
             LIMIT 1",
            $content_hash
        ) );
        if ( $existing_id ) {
            return [
                'status' => 'skip',
                'reason' => 'Duplicate email already in queue (content_hash match)',
                'sender' => $sender,
            ];
        }

        // ── Gemini classification ──────────────────────────────────────────────
        $gemini = self::classify( $sender, $subject, $body );
        if ( ! $gemini ) {
            // SAFETY FALLBACK (Part 4): Gemini failed — NEVER silently drop a notification.
            // Insert a raw queue entry and attempt immediate Telegram delivery.
            $fallback_summary = 'Unclassified inbound email — Gemini unavailable.'
                . ' From: ' . mb_substr( $sender, 0, 80 )
                . ' — Subject: ' . mb_substr( $subject, 0, 120 );

            $fallback_payload = wp_json_encode( [
                'sender'       => $sender,
                'subject'      => $subject,
                'body_excerpt' => mb_substr( $body, 0, 500 ),
                'fallback'     => true,
                'reason'       => 'Gemini classification failed',
            ], JSON_UNESCAPED_UNICODE );

            $now = current_time( 'mysql' );
            $wpdb->insert( $table, [
                'event_uuid'        => wp_generate_uuid4(),
                'event_type'        => 'OTHER.GENERAL',
                'source_system'     => OPB_Opsmail::SOURCE_HUMAN_EMAIL,
                'entity_type'       => 'EMAIL',
                'entity_id'         => null,
                'branch_id'         => null,
                'user_id'           => null,
                'origin_type'       => 'MAILBOX',
                'priority'          => 'NORMAL',
                'subject'           => mb_substr( $subject, 0, 250 ),
                'summary'           => mb_substr( $fallback_summary, 0, 500 ),
                'payload_json'      => $fallback_payload,
                'content_hash'      => $content_hash,
                'recipient_email'   => null,
                'mail_status'       => OPB_Opsmail::STATUS_ACKNOWLEDGED,
                'telegram_status'   => OPB_Opsmail::STATUS_PENDING,
                'mail_attempts'     => 0,
                'telegram_attempts' => 0,
                'classification'    => 'OTHER.UNCLASSIFIED',
                'confidence'        => null,
                'created_at'        => $now,
            ] );

            $fallback_id = (int) $wpdb->insert_id;
            $tg_ok = false;
            if ( $fallback_id && OPB_Telegram_Consumer::is_configured() ) {
                $fallback_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE id = %d", $fallback_id
                ), ARRAY_A );
                if ( $fallback_row ) {
                    $tg_ok = OPB_Telegram_Consumer::deliver_event( $fallback_row );
                }
            }

            return [
                'status'      => 'ok',
                'queue_id'    => $fallback_id ?: null,
                'sender'      => $sender,
                'subject'     => $subject,
                'fallback'    => true,
                'reason'      => 'Gemini classification failed — raw email delivered as fallback notification',
                'telegram_ok' => $tg_ok,
            ];
        }

        // ── Queue insertion ────────────────────────────────────────────────────
        $event_uuid   = wp_generate_uuid4();
        $now          = current_time( 'mysql' );
        $priority     = in_array( $gemini['priority'] ?? '', [ 'HIGH', 'NORMAL' ], true )
                            ? $gemini['priority']
                            : 'NORMAL';

        $payload_json = wp_json_encode( [
            'sender'       => $sender,
            'subject'      => $subject,
            'body_excerpt' => mb_substr( $body, 0, 500 ),
            'gemini'       => [
                'event_type'     => $gemini['event_type'],
                'classification' => $gemini['classification'],
                'confidence'     => $gemini['confidence'],
            ],
        ], JSON_UNESCAPED_UNICODE );

        $insert_data = [
            'event_uuid'        => $event_uuid,
            'event_type'        => mb_substr( (string) ( $gemini['event_type'] ?? 'OTHER.GENERAL' ), 0, 60 ),
            'source_system'     => OPB_Opsmail::SOURCE_HUMAN_EMAIL,
            'entity_type'       => 'EMAIL',
            'entity_id'         => null,
            'branch_id'         => null,
            'user_id'           => null,
            'origin_type'       => 'MAILBOX',
            'priority'          => $priority,
            'subject'           => mb_substr( $subject, 0, 250 ),
            'summary'           => mb_substr( (string) ( $gemini['summary'] ?? '' ), 0, 500 ),
            'payload_json'      => $payload_json,
            'content_hash'      => $content_hash,
            'recipient_email'   => null,
            // Inbound emails have no outbound mail to send — mark mail channel done.
            'mail_status'       => OPB_Opsmail::STATUS_ACKNOWLEDGED,
            'telegram_status'   => OPB_Opsmail::STATUS_PENDING,
            'mail_attempts'     => 0,
            'telegram_attempts' => 0,
            'created_at'        => $now,
        ];

        // Add Gemini fields if columns exist (added in v3.0.0 schema migration)
        $insert_data['classification'] = mb_substr( (string) ( $gemini['classification'] ?? '' ), 0, 100 );
        $insert_data['confidence']     = is_numeric( $gemini['confidence'] ?? '' )
                                             ? (float) $gemini['confidence']
                                             : null;

        $wpdb->insert( $table, $insert_data );

        $queue_id = (int) $wpdb->insert_id;
        if ( ! $queue_id ) {
            return [
                'status'  => 'error',
                'reason'  => 'DB insert failed: ' . $wpdb->last_error,
                'sender'  => $sender,
            ];
        }

        // ── Immediate Telegram delivery ────────────────────────────────────────
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $queue_id
        ), ARRAY_A );

        $tg_ok = false;
        if ( $row && OPB_Telegram_Consumer::is_configured() ) {
            $tg_ok = OPB_Telegram_Consumer::deliver_event( $row );
        }

        return [
            'status'         => 'ok',
            'queue_id'       => $queue_id,
            'event_uuid'     => $event_uuid,
            'sender'         => $sender,
            'event_type'     => $gemini['event_type'],
            'classification' => $gemini['classification'],
            'confidence'     => $gemini['confidence'],
            'telegram_ok'    => $tg_ok,
        ];
    }

    // ── Gemini classification ──────────────────────────────────────────────────

    /**
     * Call the Gemini API to classify an unstructured email.
     * Returns a validated array or null on any failure.
     *
     * Required response fields: event_type, priority, summary, classification, confidence
     *
     * @param  string $sender
     * @param  string $subject
     * @param  string $body
     * @return array|null
     */
    public static function classify( string $sender, string $subject, string $body ): ?array {
        try {
            $api_key = trim( OPB_Customizations::get( 'gemini_api_key' ) );
            $model   = trim( OPB_Customizations::get( 'gemini_model' ) ) ?: 'gemini-2.5-flash';

            if ( ! $api_key ) {
                error_log( '[OPB MAILBOX] classify(): Gemini API key not configured' );
                return null;
            }

            $prompt = self::build_classification_prompt( $sender, $subject, $body );

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                 . rawurlencode( $model )
                 . ':generateContent?key=' . rawurlencode( $api_key );

            $response = wp_remote_post( $url, [
                'timeout' => 20,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'contents' => [ [
                        'parts' => [ [ 'text' => $prompt ] ],
                    ] ],
                    'generationConfig' => [
                        'temperature'     => 0.1,
                        'maxOutputTokens' => 300,
                        'responseMimeType' => 'application/json',
                    ],
                ] ),
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( '[OPB MAILBOX] Gemini request error: ' . $response->get_error_message() );
                return null;
            }

            $http_code = (int) wp_remote_retrieve_response_code( $response );
            if ( $http_code !== 200 ) {
                error_log( '[OPB MAILBOX] Gemini HTTP ' . $http_code . ': ' . wp_remote_retrieve_body( $response ) );
                return null;
            }

            $api_body = json_decode( wp_remote_retrieve_body( $response ), true );
            $text     = $api_body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if ( ! $text ) {
                error_log( '[OPB MAILBOX] Gemini returned empty response body' );
                return null;
            }

            // Strip markdown fences if model returned them despite responseMimeType hint
            $clean = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
            $clean = preg_replace( '/\s*```$/i', '', $clean );

            $result = json_decode( trim( $clean ), true );
            if ( ! is_array( $result ) ) {
                error_log( '[OPB MAILBOX] Gemini returned non-JSON: ' . mb_substr( $text, 0, 300 ) );
                return null;
            }

            // Validate required fields
            $required = [ 'event_type', 'priority', 'summary', 'classification', 'confidence' ];
            foreach ( $required as $field ) {
                if ( ! isset( $result[ $field ] ) ) {
                    error_log( '[OPB MAILBOX] Gemini response missing required field: ' . $field );
                    return null;
                }
            }

            return $result;

        } catch ( \Throwable $e ) {
            error_log( '[OPB MAILBOX] classify() exception: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Build the Gemini classification prompt.
     */
    private static function build_classification_prompt(
        string $sender,
        string $subject,
        string $body
    ): string {
        $body_excerpt = mb_substr( $body, 0, 2000 );

        return <<<PROMPT
You are an operational intelligence classifier for a pet boarding business.
Analyze the inbound email below and return ONLY a valid JSON object with these exact fields.

Required JSON fields:
- event_type: string — one of: BOOKING.MODIFICATION_REQUESTED, BOOKING.CANCELLATION_REQUESTED, BOOKING.INQUIRY, SUPPORT.REQUEST_RECEIVED, PAYMENT.ISSUE_REPORTED, CLIENT.GENERAL_INQUIRY, MANAGEMENT.ANNOUNCEMENT, SYSTEM.NOTIFICATION, WOOCOMMERCE.ORDER, OTHER.GENERAL
- priority: string — "HIGH" if this requires immediate attention (complaint, cancellation, payment dispute, urgent request), otherwise "NORMAL"
- summary: string — one sentence describing what the email is about (max 150 chars)
- classification: string — a dot-notation label describing the specific action type, e.g. "BOOKING.DATE_CHANGE_REQUEST"
- confidence: number — your confidence in this classification, between 0.0 and 1.0

Email:
FROM: {$sender}
SUBJECT: {$subject}
BODY:
{$body_excerpt}

Return ONLY the JSON object. No explanation, no markdown fences, no extra text.
PROMPT;
    }

    // ── Gemini Lab: general operational text processing ────────────────────────

    /**
     * Process arbitrary text through Gemini with an operational summarization prompt.
     * Returns full diagnostics including timing, token usage, and the raw prompt sent.
     * Used by the Gemini Lab REST endpoint — NOT the mailbox classification pipeline.
     *
     * @param  string $text  Arbitrary text input (max 3000 chars used in prompt).
     * @return array         { ok, prompt, response, parsed, timing_ms, usage, error? }
     */
    public static function process_text( string $text ): array {
        try {
            $api_key = trim( OPB_Customizations::get( 'gemini_api_key' ) );
            $model   = trim( OPB_Customizations::get( 'gemini_model' ) ) ?: 'gemini-2.5-flash';

            if ( ! $api_key ) {
                return [ 'ok' => false, 'error' => 'Gemini API key not configured in Settings → Customization.' ];
            }

            $prompt = self::build_summarization_prompt( $text );
            $url    = 'https://generativelanguage.googleapis.com/v1beta/models/'
                    . rawurlencode( $model )
                    . ':generateContent?key=' . rawurlencode( $api_key );

            $start = microtime( true );

            $response = wp_remote_post( $url, [
                'timeout' => 30,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [
                    'contents'         => [ [
                        'parts' => [ [ 'text' => $prompt ] ],
                    ] ],
                    'generationConfig' => [
                        'temperature'      => 0.2,
                        'maxOutputTokens'  => 400,
                        'responseMimeType' => 'application/json',
                    ],
                ] ),
            ] );

            $timing_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

            if ( is_wp_error( $response ) ) {
                return [
                    'ok'        => false,
                    'error'     => 'Network error: ' . $response->get_error_message(),
                    'timing_ms' => $timing_ms,
                    'prompt'    => $prompt,
                ];
            }

            $http_code = (int) wp_remote_retrieve_response_code( $response );
            $body_raw  = wp_remote_retrieve_body( $response );

            if ( $http_code !== 200 ) {
                $api_err = json_decode( $body_raw, true );
                $msg     = $api_err['error']['message'] ?? ( 'HTTP ' . $http_code );
                return [
                    'ok'        => false,
                    'error'     => 'Gemini API error: ' . $msg,
                    'timing_ms' => $timing_ms,
                    'prompt'    => $prompt,
                ];
            }

            $api_body = json_decode( $body_raw, true );
            $text_out = $api_body['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $usage    = $api_body['usageMetadata'] ?? null;

            if ( ! $text_out ) {
                return [
                    'ok'        => false,
                    'error'     => 'Gemini returned an empty response. Check model name and quota.',
                    'timing_ms' => $timing_ms,
                    'prompt'    => $prompt,
                ];
            }

            // Strip markdown fences if present despite responseMimeType hint
            $clean  = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text_out ) );
            $clean  = preg_replace( '/\s*```$/i', '', $clean );
            $parsed = json_decode( trim( $clean ), true );

            return [
                'ok'        => true,
                'prompt'    => $prompt,
                'response'  => $text_out,
                'parsed'    => is_array( $parsed ) ? $parsed : null,
                'timing_ms' => $timing_ms,
                'usage'     => $usage,
            ];

        } catch ( \Throwable $e ) {
            error_log( '[OPB MAILBOX] process_text() exception: ' . $e->getMessage() );
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Build the Gemini Lab operational summarization prompt.
     * Different from the mailbox classification prompt — general purpose.
     */
    private static function build_summarization_prompt( string $text ): string {
        $excerpt  = mb_substr( $text, 0, 3000 );
        $facility = OPB_Customizations::facility_name();

        return <<<PROMPT
You are an operational intelligence assistant for "{$facility}", a pet boarding business.
Analyze the text below and produce a concise operational summary for the operations team.

Return ONLY a valid JSON object with these exact fields:
- summary: string — 1 to 3 sentences capturing the key operational point (max 250 chars)
- category: string — a brief label, e.g. "Booking Request", "Client Complaint", "Payment Issue", "Staff Update", "General Inquiry"
- priority: string — "HIGH" if immediate staff action is needed, otherwise "NORMAL"
- action_required: boolean — true if staff must take action on this
- confidence: number — your confidence in this assessment, between 0.0 and 1.0

Text to analyze:
{$excerpt}

Return ONLY the JSON object. No markdown fences, no explanation.
PROMPT;
    }

    // ── IMAP connection test ───────────────────────────────────────────────────

    /**
     * Test the IMAP connection and return diagnostic information.
     * Used by the /test-mailbox REST endpoint.
     *
     * @return array  {ok, message_count|error, host}
     */
    public static function test_connection(): array {
        try {
            if ( ! extension_loaded( 'imap' ) ) {
                return [ 'ok' => false, 'error' => 'PHP IMAP extension not available on this server' ];
            }

            $host     = OPB_Customizations::get( 'mailbox_imap_host' );
            $port     = (int) OPB_Customizations::get( 'mailbox_imap_port' ) ?: 993;
            $username = OPB_Customizations::get( 'mailbox_imap_username' );
            $password = OPB_Customizations::get( 'mailbox_imap_password' );

            if ( ! $host || ! $username || ! $password ) {
                return [ 'ok' => false, 'error' => 'IMAP credentials not fully configured' ];
            }

            $conn = @imap_open( '{' . $host . ':' . $port . '/imap/ssl}INBOX', $username, $password, 0, 1 );

            if ( ! $conn ) {
                $errors = imap_errors() ?: [];
                return [ 'ok' => false, 'error' => implode( '; ', $errors ) ?: 'IMAP connection failed' ];
            }

            $total  = (int) imap_num_msg( $conn );
            $unseen = 0;

            $uids = @imap_search( $conn, 'UNSEEN', SE_UID );
            if ( $uids ) {
                $unseen = count( $uids );
            }

            @imap_close( $conn );

            return [
                'ok'            => true,
                'host'          => $host,
                'total_messages'=> $total,
                'unseen'        => $unseen,
            ];

        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => $e->getMessage() ];
        }
    }

    // ── Configuration check ────────────────────────────────────────────────────

    /**
     * Whether the mailbox processor is enabled and fully configured.
     */
    public static function is_configured(): bool {
        try {
            if ( OPB_Customizations::get( 'mailbox_processing_enabled' ) !== '1' ) {
                return false;
            }
            $host     = trim( OPB_Customizations::get( 'mailbox_imap_host' ) );
            $username = trim( OPB_Customizations::get( 'mailbox_imap_username' ) );
            $password = trim( OPB_Customizations::get( 'mailbox_imap_password' ) );
            return $host !== '' && $username !== '' && $password !== '';
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}
