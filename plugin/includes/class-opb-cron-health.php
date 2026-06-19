<?php
/**
 * OPB_Cron_Health
 *
 * Scheduler Health Monitor (v3.2.0)
 *
 * Tracks when each OPSMAIL cron component last executed and determines
 * whether execution is healthy, delayed, or not running.
 *
 * EXTERNAL CRON DETECTION:
 *   Maintains a ring buffer of the last 12 execution timestamps.
 *   If the median interval between executions is < 8 minutes, an external
 *   server cron is likely configured.
 *   If DISABLE_WP_CRON is defined and true, external cron is confirmed.
 *
 * SAFETY GUARANTEE:
 *   All methods are wrapped in try/catch. This class will never throw.
 */
class OPB_Cron_Health {

    // ── WP option keys ─────────────────────────────────────────────────────────
    const OPT_QUEUE   = 'opb_cron_ping_queue';
    const OPT_MAILBOX = 'opb_cron_ping_mailbox';
    const OPT_SAL     = 'opb_cron_ping_sal';
    const OPT_HISTORY = 'opb_cron_ping_history';

    /**
     * Health thresholds per component.
     * 'healthy'  — max elapsed seconds to be considered healthy.
     * 'delayed'  — max elapsed seconds before marked not_running.
     */
    const THRESHOLDS = [
        'queue'   => [ 'healthy' => 180,  'delayed' => 900   ], // 3 min / 15 min
        'mailbox' => [ 'healthy' => 900,  'delayed' => 3600  ], // 15 min / 60 min
        'sal'     => [ 'healthy' => 5400, 'delayed' => 10800 ], // 90 min / 3 hr
    ];

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Record that a cron component has just executed.
     * Called from each cron handler in the main plugin file.
     *
     * @param string $component  'queue' | 'mailbox' | 'sal'
     */
    public static function record_ping( string $component ): void {
        try {
            update_option( "opb_cron_ping_{$component}", time(), false );

            // Append to ring buffer for external cron detection
            $history = get_option( self::OPT_HISTORY, [] );
            if ( ! is_array( $history ) ) {
                $history = [];
            }
            $history[] = time();
            if ( count( $history ) > 12 ) {
                $history = array_slice( $history, -12 );
            }
            update_option( self::OPT_HISTORY, $history, false );
        } catch ( \Throwable $e ) {
            // Never throw from a cron path
        }
    }

    /**
     * Return a full health snapshot.
     *
     * @return array {
     *   site_url, wp_cron_url, wp_cron_disabled,
     *   components: { queue, mailbox, sal },
     *   external_cron: 'detected'|'unknown'|'not_detected',
     *   overall_status: 'healthy'|'delayed'|'not_running',
     *   cron_active: bool,
     *   recommended_cron_command: string,
     *   recommended_frequency: string,
     * }
     */
    public static function get_health(): array {
        try {
            $now      = time();
            $site_url = get_bloginfo( 'url' );
            $cron_url = trailingslashit( $site_url ) . 'wp-cron.php?doing_wp_cron';

            $components = [];
            foreach ( [ 'queue', 'mailbox', 'sal' ] as $c ) {
                $components[ $c ] = self::component_status( $c, $now );
            }

            $statuses = array_column( $components, 'status' );
            if ( in_array( 'not_running', $statuses, true ) ) {
                $overall = 'not_running';
            } elseif ( in_array( 'delayed', $statuses, true ) ) {
                $overall = 'delayed';
            } else {
                $overall = 'healthy';
            }

            return [
                'site_url'                 => $site_url,
                'wp_cron_url'              => $cron_url,
                'wp_cron_disabled'         => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                'components'               => $components,
                'external_cron'            => self::detect_external_cron(),
                'overall_status'           => $overall,
                'cron_active'              => (bool) wp_next_scheduled( 'opb_cron_process_telegram' ),
                'recommended_cron_command' => "curl -s '{$cron_url}' >/dev/null 2>&1",
                'recommended_frequency'    => '*/5 * * * *',
            ];
        } catch ( \Throwable $e ) {
            return [ 'error' => $e->getMessage() ];
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private static function component_status( string $component, int $now ): array {
        $last = get_option( "opb_cron_ping_{$component}" );
        $t    = self::THRESHOLDS[ $component ];

        if ( ! $last ) {
            return [
                'status'                => 'not_running',
                'last_run'              => null,
                'elapsed_sec'           => null,
                'healthy_threshold_sec' => $t['healthy'],
                'delayed_threshold_sec' => $t['delayed'],
            ];
        }

        $elapsed = $now - (int) $last;
        $status  = $elapsed < $t['healthy'] ? 'healthy'
                 : ( $elapsed < $t['delayed'] ? 'delayed' : 'not_running' );

        return [
            'status'                => $status,
            'last_run'              => gmdate( 'Y-m-d H:i:s', (int) $last ),
            'elapsed_sec'           => $elapsed,
            'healthy_threshold_sec' => $t['healthy'],
            'delayed_threshold_sec' => $t['delayed'],
        ];
    }

    /**
     * Analyse the ping ring buffer to guess whether an external cron is active.
     *
     * Rules (in order):
     *  1. DISABLE_WP_CRON = true  → 'detected'  (WP cron must be externally triggered)
     *  2. < 4 data points         → 'unknown'   (not enough history)
     *  3. Median interval < 8 min → 'detected'
     *  4. Median interval < 20min → 'unknown'
     *  5. Otherwise               → 'not_detected'
     */
    private static function detect_external_cron(): string {
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return 'detected';
        }

        $history = get_option( self::OPT_HISTORY, [] );
        if ( ! is_array( $history ) || count( $history ) < 4 ) {
            return 'unknown';
        }

        sort( $history );
        $intervals = [];
        for ( $i = 1, $n = count( $history ); $i < $n; $i++ ) {
            $intervals[] = $history[ $i ] - $history[ $i - 1 ];
        }
        sort( $intervals );
        $median = $intervals[ (int) ( count( $intervals ) / 2 ) ];

        if ( $median < 480 )  return 'detected';
        if ( $median < 1200 ) return 'unknown';
        return 'not_detected';
    }
}
