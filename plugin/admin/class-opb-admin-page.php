<?php
class OPB_Admin_Page {

    public static function register_menu(): void {
        add_menu_page(
            'Pet Boarding',
            'Pet Boarding',
            'manage_options',
            'opb-dashboard',
            [ self::class, 'render' ],
            'dashicons-pets',
            30
        );

        $screens = [
            'opb-dashboard'  => 'Dashboard',
            'opb-clients'    => 'Clients',
            'opb-pets'       => 'Pets',
            'opb-bookings'   => 'Bookings',
            'opb-kennel'     => 'Kennel Board',
            'opb-invoices'   => 'Invoices',
            'opb-tasks'      => 'Tasks',
            'opb-expenses'   => 'Expenses',
            'opb-settings'   => 'Settings',
            'opb-import'     => 'Import',
        ];

        foreach ( $screens as $slug => $label ) {
            add_submenu_page(
                'opb-dashboard',
                $label,
                $label,
                'manage_options',
                $slug,
                [ self::class, 'render' ]
            );
        }
    }

    public static function render(): void {
        echo '<div id="opb-root"></div>';
    }

    public static function enqueue_assets(): void {
        $dist = OPB_PLUGIN_URL . 'assets/dist/';

        // Vite generates assets with hashes; we use a manifest if present
        $manifest_path = OPB_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';
        $js_file  = 'assets/index.js';
        $css_file = 'assets/index.css';

        if ( file_exists( $manifest_path ) ) {
            $manifest = json_decode( file_get_contents( $manifest_path ), true );
            $entry    = $manifest['src/main.tsx'] ?? null;
            if ( $entry ) {
                $js_file  = $entry['file'] ?? $js_file;
                $css_file = $entry['css'][0] ?? $css_file;
            }
        }

        wp_enqueue_script(
            'opb-app',
            $dist . $js_file,
            [],
            OPB_VERSION,
            true
        );

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
            'logoutUrl' => wp_logout_url( admin_url() ),
            'user'      => [
                'id'       => $user->ID,
                'name'     => $user->display_name,
                'roles'    => $user->roles,
                'branchId' => (int) get_user_meta( $user->ID, 'opb_branch_id', true ),
            ],
        ] );
    }
}
