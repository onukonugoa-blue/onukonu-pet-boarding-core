<?php
/**
 * OPB Portal — Dedicated staff-facing portal experience.
 *
 * Responsibilities:
 *  - Registers a clean /portal/ URL (rewrite rule + query var)
 *  - Serves /opb-sw.js and /opb-manifest.json from the plugin assets dir
 *  - Redirects OPB-role users to /portal/ after WordPress login
 *  - Hides the WP admin bar for OPB-only users on the frontend
 *  - Redirects OPB-only users away from wp-admin back to /portal/
 *  - Enqueues the React SPA assets on the portal page (same bundle as wp-admin)
 *  - Renders a minimal full-screen HTML document (no theme, no admin chrome)
 */
class OPB_Portal {

    public static function register(): void {
        add_action( 'init',               [ self::class, 'add_rewrite_rules'           ] );
        add_filter( 'query_vars',         [ self::class, 'add_query_vars'              ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'maybe_enqueue_portal_assets' ] );
        add_action( 'template_redirect',  [ self::class, 'maybe_serve_portal'          ] );
        add_filter( 'login_redirect',     [ self::class, 'login_redirect'             ], 10, 3 );
        add_filter( 'show_admin_bar',     [ self::class, 'maybe_hide_admin_bar'        ] );
        add_action( 'admin_init',         [ self::class, 'maybe_redirect_from_admin'   ] );
    }

    // ── Rewrite rules ──────────────────────────────────────────────────────────

    public static function add_rewrite_rules(): void {
        add_rewrite_rule( '^portal/?$',             'index.php?opb_portal=1',   'top' );
        add_rewrite_rule( '^opb-sw\.js$',           'index.php?opb_sw=1',       'top' );
        add_rewrite_rule( '^opb-manifest\.json$',   'index.php?opb_manifest=1', 'top' );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'opb_portal';
        $vars[] = 'opb_sw';
        $vars[] = 'opb_manifest';
        return $vars;
    }

    // ── Asset file pass-throughs ───────────────────────────────────────────────

    public static function maybe_serve_portal(): void {
        if ( get_query_var( 'opb_sw' ) ) {
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            header( 'X-Content-Type-Options: nosniff' );
            readfile( OPB_PLUGIN_DIR . 'assets/sw.js' );
            exit;
        }

        if ( get_query_var( 'opb_manifest' ) ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'Cache-Control: no-cache' );
            readfile( OPB_PLUGIN_DIR . 'assets/manifest.json' );
            exit;
        }

        if ( ! get_query_var( 'opb_portal' ) ) {
            return;
        }

        // ── Auth gate ─────────────────────────────────────────────────────────
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( self::portal_url() ) );
            exit;
        }

        if ( ! OPB_Roles::has_opb_role() ) {
            wp_redirect( admin_url() );
            exit;
        }

        self::render_portal();
        exit;
    }

    // ── SPA asset enqueue (fires inside wp_head()) ─────────────────────────────

    public static function maybe_enqueue_portal_assets(): void {
        if ( ! get_query_var( 'opb_portal' ) ) {
            return;
        }

        $dist          = OPB_PLUGIN_URL . 'assets/dist/';
        $manifest_path = OPB_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';
        $js_file       = 'assets/index.js';
        $css_file      = 'assets/main.css';

        if ( file_exists( $manifest_path ) ) {
            $manifest = json_decode( file_get_contents( $manifest_path ), true );
            $entry    = $manifest['src/main.tsx'] ?? null;
            if ( $entry ) {
                $js_file  = $entry['file'] ?? $js_file;
                $css_file = $entry['css'][0] ?? $css_file;
            }
        }

        wp_enqueue_script( 'opb-app', $dist . $js_file, [], OPB_VERSION, true );

        add_filter( 'script_loader_tag', static function ( string $tag, string $handle ) use ( $dist, $js_file ): string {
            if ( $handle === 'opb-app' ) {
                $tag = str_replace( '<script ', '<script type="module" ', $tag );
            }
            return $tag;
        }, 10, 2 );

        if ( $css_file ) {
            wp_enqueue_style( 'opb-app-style', $dist . $css_file, [], OPB_VERSION );
        }

        $user = wp_get_current_user();

        wp_localize_script( 'opb-app', 'OPB', [
            'apiBase'   => rest_url( 'opb/v1' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'adminUrl'  => admin_url( 'admin.php' ),
            'logoutUrl' => wp_logout_url( self::portal_url() ),
            'user'      => [
                'id'       => $user->ID,
                'name'     => $user->display_name,
                'roles'    => $user->roles,
                'branchId' => (int) get_user_meta( $user->ID, 'opb_branch_id', true ),
            ],
        ] );
    }

    // ── Portal HTML document ───────────────────────────────────────────────────

    private static function render_portal(): void {
        $icon_base    = OPB_PLUGIN_URL . 'assets/icons/';
        $manifest_url = home_url( '/opb-manifest.json' );
        $sw_url       = home_url( '/opb-sw.js' );
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1e3a5f">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="OPB">
<title>Onukonu Pet Boarding</title>
<link rel="manifest" href="<?php echo esc_url( $manifest_url ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $icon_base . 'icon-192.svg' ); ?>">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $icon_base . 'icon-192.svg' ); ?>">
<?php wp_head(); ?>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; background: #1e3a5f; overflow: hidden; }
  #opb-root { height: 100%; }
</style>
</head>
<body>
<div id="opb-root"></div>
<?php wp_footer(); ?>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(
            <?php echo wp_json_encode( $sw_url ); ?>,
            { scope: '/portal/' }
        ).catch(function (err) {
            console.warn('[OPB SW] Registration failed:', err);
        });
    });
}
</script>
</body>
</html>
<?php
    }

    // ── Redirect helpers ───────────────────────────────────────────────────────

    /**
     * After login, send OPB-only users to the portal instead of wp-admin.
     * WP Administrators land in wp-admin as normal.
     */
    public static function login_redirect( string $redirect_to, string $requested_redirect_to, WP_User|WP_Error $user ): string {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) ) {
            return $redirect_to;
        }
        if ( ! self::is_opb_only_user( $user ) ) {
            return $redirect_to;
        }
        return self::portal_url();
    }

    /**
     * Hide the WP admin bar for OPB-only users on all frontend pages.
     */
    public static function maybe_hide_admin_bar( bool $show ): bool {
        if ( ! is_user_logged_in() ) {
            return $show;
        }
        if ( self::is_opb_only_user( wp_get_current_user() ) ) {
            return false;
        }
        return $show;
    }

    /**
     * If an OPB-only user somehow lands in wp-admin, bounce them back to the portal.
     * Skips AJAX / REST requests.
     */
    public static function maybe_redirect_from_admin(): void {
        if ( wp_doing_ajax() ) {
            return;
        }
        if ( ! self::is_opb_only_user( wp_get_current_user() ) ) {
            return;
        }
        wp_redirect( self::portal_url() );
        exit;
    }

    // ── Utilities ──────────────────────────────────────────────────────────────

    public static function portal_url(): string {
        return home_url( '/portal/' );
    }

    /**
     * Returns true when the user holds an OPB role but NOT manage_options
     * (i.e. they should never need wp-admin).
     */
    private static function is_opb_only_user( WP_User $user ): bool {
        if ( user_can( $user, 'manage_options' ) ) {
            return false;
        }
        foreach ( array_keys( OPB_Roles::ROLES ) as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return false;
    }
}
