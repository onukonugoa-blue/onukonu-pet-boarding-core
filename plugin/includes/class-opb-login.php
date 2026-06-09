<?php
/**
 * OPB Login — Branded WordPress login page skin.
 *
 * Responsibilities:
 *  - Enqueues assets/login.css on wp-login.php
 *  - Points the logo link to site home
 *  - Sets accessible logo title text
 *
 * Constraints (enforced by design):
 *  - CSS-only branding — no JavaScript, no DOM manipulation
 *  - No injected containers or wrappers
 *  - No authentication, Loginizer, redirect, or session changes
 *  - No database reads or writes
 *  - No settings or configuration UI
 *  - WordPress controls all layout, width, and responsiveness
 */
class OPB_Login {

    public static function register(): void {
        add_action( 'login_enqueue_scripts', [ self::class, 'enqueue_login_styles' ] );
        add_filter( 'login_headerurl',       [ self::class, 'login_header_url'     ] );
        add_filter( 'login_headertext',      [ self::class, 'login_header_text'    ] );
    }

    // ── Asset enqueue ─────────────────────────────────────────────────────────

    public static function enqueue_login_styles(): void {
        wp_enqueue_style(
            'opb-login',
            OPB_PLUGIN_URL . 'assets/login.css',
            [],
            OPB_VERSION
        );
    }

    // ── Header link / text ────────────────────────────────────────────────────

    /** Logo link points to site home, not wordpress.org. */
    public static function login_header_url(): string {
        return home_url( '/' );
    }

    /** Accessible label for the logo anchor. */
    public static function login_header_text(): string {
        return 'Onukonu Pet Boarding — Operations Portal';
    }
}
