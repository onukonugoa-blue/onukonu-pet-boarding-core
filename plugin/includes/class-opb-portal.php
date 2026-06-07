<?php
/**
 * OPB Portal — Dedicated staff-facing portal experience.
 *
 * Responsibilities:
 *  - Registers a clean /portal/ URL (rewrite rule + query var)
 *  - Serves /?opb_sw=1 and /?opb_manifest=1 (query-parameter endpoints, bypass Apache)
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

        /*
         * Self-healing flush guard.
         *
         * register_activation_hook only fires when the plugin is toggled via the
         * Plugins page. ZIP-based updates on cPanel / Hostinger replace files while
         * the plugin stays active — neither activate nor deactivate fires, so the
         * cached rewrite rules in wp_options are never refreshed and /portal/ falls
         * through to normal WordPress routing.
         *
         * Solution: after registering the rules on every init, compare the version
         * stored in options against OPB_VERSION. If they differ (first run after any
         * update), flush once and record the new version. All subsequent requests
         * skip the flush — no performance cost.
         */
        if ( get_option( 'opb_rewrite_version' ) !== OPB_VERSION ) {
            flush_rewrite_rules( false );
            update_option( 'opb_rewrite_version', OPB_VERSION, true );
        }
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
            /*
             * Allow the SW (served from root path /opb-sw.js) to claim the /portal/
             * scope. Browsers permit narrowing the default scope without this header,
             * but some strict configurations require it to be explicit.
             */
            header( 'Service-Worker-Allowed: /' );
            readfile( OPB_PLUGIN_DIR . 'assets/sw.js' );
            exit;
        }

        if ( get_query_var( 'opb_manifest' ) ) {
            header( 'Content-Type: application/manifest+json; charset=utf-8' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            echo wp_json_encode( self::build_manifest(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
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
        $manifest_url = home_url( '/?opb_manifest=1' );
        $sw_url       = home_url( '/?opb_sw=1' );
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
<link rel="apple-touch-icon" href="<?php echo esc_url( $icon_base . 'icon-192.png' ); ?>">
<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $icon_base . 'icon-192.svg' ); ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icon_base . 'icon-192.png' ); ?>">
<?php
/*
 * Capture beforeinstallprompt BEFORE any module scripts load.
 *
 * Chrome fires beforeinstallprompt very early — often before DOMContentLoaded
 * and certainly before React mounts. useEffect listeners added after the first
 * render always miss it. This inline synchronous script attaches the listener
 * at parse time (top of <head>), stores the event on window.__opbDeferredInstall,
 * and lets the usePWAInstall hook read it on mount.
 */
?>
<script>window.__opbDeferredInstall=null;window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();window.__opbDeferredInstall=e;},{once:true,capture:true});</script>
<?php wp_head(); ?>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; height: 100%; background: #1e3a5f; overflow: hidden; }
  #opb-root { height: 100%; }
  /* Safe-area insets for notch/home-indicator devices */
  :root {
    --sat: env(safe-area-inset-top, 0px);
    --sab: env(safe-area-inset-bottom, 0px);
    --sal: env(safe-area-inset-left, 0px);
    --sar: env(safe-area-inset-right, 0px);
  }
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

    // ── Manifest builder ──────────────────────────────────────────────────────

    /**
     * Build the Web App Manifest array dynamically from OPB Customization settings.
     * Falls back to static defaults when customizations are not yet configured.
     */
    private static function build_manifest(): array {
        $facility_name = OPB_Customizations::get( 'facility_name' );
        if ( empty( $facility_name ) ) {
            $facility_name = 'OPB – Pet Boarding';
        }

        $icon_base = '/wp-content/plugins/onukonu-pet-boarding-core/assets/icons/';

        return [
            'id'               => '/portal/',
            'name'             => $facility_name,
            'short_name'       => 'OPB',
            'description'      => 'Staff portal for ' . $facility_name . ' operations',
            'start_url'        => '/portal/',
            'scope'            => '/portal/',
            'display'          => 'standalone',
            'display_override' => [ 'standalone', 'minimal-ui', 'browser' ],
            'orientation'      => 'portrait',
            'theme_color'      => '#1e3a5f',
            'background_color' => '#ffffff',
            'lang'             => 'en',
            'icons'            => [
                [
                    'src'     => $icon_base . 'icon-192.png',
                    'sizes'   => '192x192',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => $icon_base . 'icon-512.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src'     => $icon_base . 'icon-maskable.png',
                    'sizes'   => '512x512',
                    'type'    => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src'     => $icon_base . 'icon-192.svg',
                    'sizes'   => '192x192',
                    'type'    => 'image/svg+xml',
                    'purpose' => 'any',
                ],
                [
                    'src'     => $icon_base . 'icon-512.svg',
                    'sizes'   => '512x512',
                    'type'    => 'image/svg+xml',
                    'purpose' => 'any',
                ],
            ],
            'screenshots'  => [],
            'categories'   => [ 'business', 'utilities' ],
        ];
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
