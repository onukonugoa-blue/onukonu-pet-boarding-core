<?php
/**
 * OPB Login — Branded WordPress login page skin.
 *
 * Responsibilities:
 *  - Enqueues assets/login.css on the wp-login.php page
 *  - Sets the logo link URL and accessible title
 *  - Injects a branding subtitle block (title + subheading) via login_footer
 *
 * Constraints (enforced by design):
 *  - No authentication changes
 *  - No Loginizer changes
 *  - No redirect changes
 *  - No database reads or writes
 *  - No settings or configuration UI
 *  - Static CSS + WordPress hooks only
 */
class OPB_Login {

    public static function register(): void {
        add_action( 'login_enqueue_scripts', [ self::class, 'enqueue_login_styles'  ] );
        add_action( 'login_footer',          [ self::class, 'render_brand_block'    ] );
        add_filter( 'login_headerurl',       [ self::class, 'login_header_url'      ] );
        add_filter( 'login_headertext',      [ self::class, 'login_header_text'     ] );
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

    // ── Branding subtitle block ───────────────────────────────────────────────

    /**
     * Inject "Onukonu Pet Boarding / Operations Portal" beneath the logo.
     * Uses a small inline script so it never conflicts with form markup.
     */
    public static function render_brand_block(): void {
        ?>
        <script>
        (function () {
            'use strict';
            var h1 = document.querySelector('#login h1');
            if ( ! h1 ) { return; }
            var div = document.createElement('div');
            div.className = 'opb-login-brand';
            div.innerHTML =
                '<span class="opb-login-brand__title">Onukonu Pet Boarding</span>' +
                '<span class="opb-login-brand__sub">Operations Portal</span>';
            h1.insertAdjacentElement('afterend', div);
        }());
        </script>
        <?php
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
