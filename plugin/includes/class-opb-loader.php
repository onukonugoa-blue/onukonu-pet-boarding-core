<?php
/**
 * Orchestrates plugin hooks and REST API registration.
 */
class OPB_Loader {

    public function run(): void {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'admin_menu',    [ $this, 'register_admin_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function register_rest_routes(): void {
        // REST routes will be registered here as each endpoint class is built.
        // Example: ( new OPB_Clients_API() )->register_routes();
    }

    public function register_admin_menu(): void {
        add_menu_page(
            'Pet Boarding',
            'Pet Boarding',
            'manage_options',
            'opb-dashboard',
            [ $this, 'render_spa_page' ],
            'dashicons-pets',
            30
        );
    }

    public function render_spa_page(): void {
        echo '<div id="opb-root"></div>';
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( strpos( $hook, 'opb-' ) === false ) {
            return;
        }
        // Enqueue the React SPA bundle (built into plugin/frontend/dist/).
        $dist = OPB_PLUGIN_URL . 'frontend/dist/';
        wp_enqueue_script(
            'opb-app',
            $dist . 'assets/index.js',
            [],
            OPB_VERSION,
            true
        );
        wp_enqueue_style(
            'opb-app-style',
            $dist . 'assets/index.css',
            [],
            OPB_VERSION
        );
        wp_localize_script( 'opb-app', 'OPB', [
            'apiBase' => rest_url( 'opb/v1' ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'user'    => [
                'id'    => get_current_user_id(),
                'roles' => wp_get_current_user()->roles,
            ],
        ] );
    }
}
