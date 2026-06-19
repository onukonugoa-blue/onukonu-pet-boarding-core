<?php
/**
 * OPB_SAL_API
 *
 * Situational Awareness Layer — REST API (v3.1.0)
 *
 * GET  /opb/v1/sal/config                — Get SAL schedule & Telegram config
 * POST /opb/v1/sal/config                — Save SAL config
 * POST /opb/v1/sal/generate              — Generate snapshot (preview mode, no send)
 * POST /opb/v1/sal/send                  — Generate + queue + deliver to Telegram
 * POST /opb/v1/sal/test-telegram         — Test SAL Telegram chat ID
 * GET  /opb/v1/sal/diagnostics           — Last run metadata per brief type
 *
 * Permission: manage_options (WP administrator / opb_super_admin)
 */
class OPB_SAL_API extends OPB_REST_Base {

    public function register_routes(): void {
        $ns = $this->namespace;

        register_rest_route( $ns, '/sal/config', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_config' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'save_config' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/sal/generate', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'generate_preview' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/sal/send', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'send_brief' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/sal/test-telegram', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'test_telegram' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );

        register_rest_route( $ns, '/sal/diagnostics', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_diagnostics' ],
                'permission_callback' => [ $this, 'super_admin_only' ],
            ],
        ] );
    }

    public function super_admin_only( WP_REST_Request $r ): bool|WP_Error {
        return $this->permission_manage( 'manage_options', $r );
    }

    // ── Config ─────────────────────────────────────────────────────────────────

    /**
     * GET /opb/v1/sal/config
     * Returns current SAL schedule + Telegram settings.
     */
    public function get_config( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        return $this->success( [
            'sal_enabled'                    => OPB_Customizations::get( 'sal_enabled' ) !== '0',
            'sal_morning_brief_enabled'      => OPB_Customizations::get( 'sal_morning_brief_enabled' ) !== '0',
            'sal_morning_brief_time'         => OPB_Customizations::get( 'sal_morning_brief_time' ) ?: '07:00',
            'sal_evening_brief_enabled'      => OPB_Customizations::get( 'sal_evening_brief_enabled' ) !== '0',
            'sal_evening_brief_time'         => OPB_Customizations::get( 'sal_evening_brief_time' ) ?: '19:00',
            'sal_accounts_snapshot_enabled'  => OPB_Customizations::get( 'sal_accounts_snapshot_enabled' ) !== '0',
            'sal_accounts_snapshot_time'     => OPB_Customizations::get( 'sal_accounts_snapshot_time' ) ?: '09:00',
            'sal_telegram_chat_id'           => OPB_Customizations::get( 'sal_telegram_chat_id' ),
            'sal_telegram_configured'        => OPB_SAL_API::sal_chat_id() !== '',
            'sal_fallback_chat_id'           => OPB_Customizations::get( 'telegram_chat_id' ),
            'next_scheduled'                 => self::next_scheduled_times(),
        ] );
    }

    /**
     * POST /opb/v1/sal/config
     * Saves SAL schedule and Telegram settings.
     */
    public function save_config( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $keys = [
            'sal_enabled',
            'sal_morning_brief_enabled',
            'sal_morning_brief_time',
            'sal_evening_brief_enabled',
            'sal_evening_brief_time',
            'sal_accounts_snapshot_enabled',
            'sal_accounts_snapshot_time',
            'sal_telegram_chat_id',
        ];

        foreach ( $keys as $key ) {
            $val = $r->get_param( $key );
            if ( $val !== null ) {
                OPB_Customizations::set( $key, (string) $val );
            }
        }

        // Reschedule cron on config change
        OPB_SAL_Scheduler::reschedule_all();

        return $this->success( [ 'ok' => true, 'message' => 'SAL configuration saved.' ] );
    }

    // ── Generate preview ───────────────────────────────────────────────────────

    /**
     * POST /opb/v1/sal/generate
     * Generate a full preview pipeline without sending.
     * Body: { brief_type: 'morning'|'evening'|'accounts' }
     *
     * Returns the snapshot JSON, prompt, Gemini output, and final Telegram message.
     */
    public function generate_preview( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $brief_type = sanitize_text_field( $r->get_param( 'brief_type' ) ?? 'morning' );
        if ( ! in_array( $brief_type, [ 'morning', 'evening', 'accounts' ], true ) ) {
            return $this->error( 'invalid_type', 'brief_type must be morning, evening, or accounts.', 422 );
        }

        $snapshot  = OPB_SAL_Snapshot::generate( $brief_type );
        $formatted = OPB_SAL_Formatter::format( $snapshot );

        return $this->success( [
            'ok'               => true,
            'brief_type'       => $brief_type,
            'snapshot'         => $snapshot,
            'prompt'           => $formatted['prompt'],
            'gemini_output'    => $formatted['gemini_output'],
            'telegram_message' => $formatted['telegram_message'],
            'used_fallback'    => $formatted['used_fallback'],
            'timing_ms'        => $formatted['timing_ms'],
        ] );
    }

    // ── Send brief ─────────────────────────────────────────────────────────────

    /**
     * POST /opb/v1/sal/send
     * Generate snapshot → format → queue → deliver to Telegram.
     * Body: { brief_type: 'morning'|'evening'|'accounts' }
     */
    public function send_brief( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $brief_type = sanitize_text_field( $r->get_param( 'brief_type' ) ?? 'morning' );
        if ( ! in_array( $brief_type, [ 'morning', 'evening', 'accounts' ], true ) ) {
            return $this->error( 'invalid_type', 'brief_type must be morning, evening, or accounts.', 422 );
        }

        $chat_id = self::sal_chat_id();
        if ( ! $chat_id ) {
            return $this->error( 'not_configured', 'SAL Telegram Chat ID is not configured. Set a Reporting Chat ID under SAL → Telegram Configuration, or configure the main Telegram chat ID.', 422 );
        }

        $result = OPB_SAL_Scheduler::run_brief( $brief_type, 'manual' );

        if ( ! ( $result['ok'] ?? false ) ) {
            return $this->error( 'send_failed', $result['error'] ?? 'Failed to send SAL brief.', 500 );
        }

        return $this->success( [
            'ok'               => true,
            'brief_type'       => $brief_type,
            'queue_id'         => $result['queue_id'] ?? null,
            'telegram_ok'      => $result['telegram_ok'] ?? false,
            'used_fallback'    => $result['used_fallback'] ?? false,
            'timing_ms'        => $result['timing_ms'] ?? 0,
        ] );
    }

    // ── Test Telegram ──────────────────────────────────────────────────────────

    /**
     * POST /opb/v1/sal/test-telegram
     * Send a test message to the SAL Telegram chat.
     */
    public function test_telegram( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $chat_id = self::sal_chat_id();
        if ( ! $chat_id ) {
            return $this->error( 'not_configured', 'SAL Telegram Chat ID is not configured.', 422 );
        }

        $bot_token = trim( OPB_Customizations::get( 'telegram_bot_token' ) );
        if ( ! $bot_token ) {
            return $this->error( 'not_configured', 'Telegram Bot Token is not configured.', 422 );
        }

        $site = get_bloginfo( 'name' );
        $time = current_time( 'mysql' );
        $text = "🧪 <b>OPB SAL Test Message</b>\n"
              . "Site: <i>" . htmlspecialchars( $site, ENT_QUOTES ) . "</i>\n"
              . "Time: <code>{$time}</code>\n"
              . "Status: ✅ SAL Telegram delivery verified.";

        $ok = OPB_Telegram_Consumer::send_telegram_to( $bot_token, $chat_id, $text );

        if ( ! $ok ) {
            return $this->error( 'telegram_failed', 'SAL Telegram test failed — check bot token, SAL chat ID, and network access.', 502 );
        }

        return $this->success( [ 'ok' => true, 'message' => 'SAL test message delivered to Telegram.' ] );
    }

    // ── Diagnostics ────────────────────────────────────────────────────────────

    /**
     * GET /opb/v1/sal/diagnostics
     * Returns last run metadata for each brief type.
     */
    public function get_diagnostics( WP_REST_Request $r ): WP_REST_Response|WP_Error {
        $types = [ 'morning', 'evening', 'accounts' ];
        $diag  = [];

        foreach ( $types as $type ) {
            $diag[ $type ] = [
                'last_run'     => get_option( "opb_sal_last_run_{$type}",     null ),
                'last_success' => get_option( "opb_sal_last_success_{$type}", null ),
                'last_failure' => get_option( "opb_sal_last_failure_{$type}", null ),
                'last_error'   => get_option( "opb_sal_last_error_{$type}",   null ),
                'next_run'     => OPB_SAL_Scheduler::next_run_time( $type ),
            ];
        }

        return $this->success( [
            'diagnostics'  => $diag,
            'cron_active'  => (bool) wp_next_scheduled( 'opb_cron_sal_check' ),
        ] );
    }

    // ── Static helpers ─────────────────────────────────────────────────────────

    /**
     * Resolve the SAL reporting chat ID.
     * Prefers dedicated SAL chat ID; falls back to main Telegram chat ID.
     */
    public static function sal_chat_id(): string {
        $sal_id = trim( OPB_Customizations::get( 'sal_telegram_chat_id' ) );
        if ( $sal_id !== '' ) {
            return $sal_id;
        }
        return trim( OPB_Customizations::get( 'telegram_chat_id' ) );
    }

    /**
     * Return next scheduled time strings for each brief type.
     */
    private static function next_scheduled_times(): array {
        return [
            'morning'  => OPB_SAL_Scheduler::next_run_time( 'morning' ),
            'evening'  => OPB_SAL_Scheduler::next_run_time( 'evening' ),
            'accounts' => OPB_SAL_Scheduler::next_run_time( 'accounts' ),
        ];
    }
}
