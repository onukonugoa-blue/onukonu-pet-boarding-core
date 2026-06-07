<?php
/**
 * OPB Health API
 *
 * Exposes a lightweight diagnostic endpoint that the React portal calls
 * on startup to verify the /portal/ rewrite rule is correctly stored in
 * WordPress's route cache.
 *
 * GET /wp-json/opb/v1/health/portal
 *
 * Only accessible to authenticated OPB users.
 * Shows actionable warnings only to super-admins and administrators
 * (the client hides the banner for lower-privilege roles).
 */
class OPB_Health_API extends OPB_REST_Base {

    public function register_routes(): void {
        register_rest_route( $this->namespace, '/health/portal', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_portal_health' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ],
        ] );
    }

    public function get_portal_health( WP_REST_Request $request ): WP_REST_Response {

        $checks   = [];
        $ok       = true;
        $reasons  = [];

        // ── Check 1: rewrite version option matches current plugin ─────────────
        $stored_version = get_option( 'opb_rewrite_version', '' );
        $version_ok     = ( $stored_version === OPB_VERSION );
        $checks['rewrite_version_matches'] = $version_ok;
        if ( ! $version_ok ) {
            $ok        = false;
            $reasons[] = "Rewrite version mismatch: stored '{$stored_version}', expected '" . OPB_VERSION . "'. "
                       . 'The self-healing flush guard will correct this on the next full page load.';
        }

        // ── Check 2: portal rule is present in the stored rewrite rules ────────
        $rewrite_rules = get_option( 'rewrite_rules', [] );
        $portal_rule_present = false;
        foreach ( array_keys( (array) $rewrite_rules ) as $pattern ) {
            if ( str_contains( $pattern, 'portal' ) ) {
                $portal_rule_present = true;
                break;
            }
        }
        $checks['portal_rule_in_cache'] = $portal_rule_present;
        if ( ! $portal_rule_present ) {
            $ok        = false;
            $reasons[] = 'The /portal/ rewrite rule is not in the WordPress route cache. '
                       . 'Go to Settings → Permalinks and click Save Changes to force a flush.';
        }

        // ── Check 3: opb_portal query var is registered ────────────────────────
        global $wp;
        $qv_registered = in_array( 'opb_portal', (array) $wp->public_query_vars, true );
        $checks['opb_portal_query_var_registered'] = $qv_registered;
        if ( ! $qv_registered ) {
            $ok        = false;
            $reasons[] = "Query var 'opb_portal' is not registered. The plugin may not have initialised correctly.";
        }

        return $this->success( [
            'ok'      => $ok,
            'version' => OPB_VERSION,
            'checks'  => $checks,
            'reasons' => $reasons,
        ] );
    }
}
