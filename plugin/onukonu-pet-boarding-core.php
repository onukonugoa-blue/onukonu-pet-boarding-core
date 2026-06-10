<?php
/**
 * Plugin Name: Onukonu Pet Boarding Core
 * Plugin URI:  https://onukonu.com
 * Description: Replacement platform for the discontinued boarding SaaS. Manages clients, pets, bookings, invoices, payments, and operations across three branches.
 * Version:     2.4.0
 * Author:      Onukonu Pet Homestyle Boarding
 * License:     GPL-2.0-or-later
 * Text Domain: opb
 * Requires PHP: 8.2
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OPB_VERSION',     '2.4.0' );
define( 'OPB_PLUGIN_FILE', __FILE__ );
define( 'OPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'OPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Composer autoloader — must come before any class that depends on vendor packages (e.g. mPDF)
require_once OPB_PLUGIN_DIR . 'vendor/autoload.php';

require_once OPB_PLUGIN_DIR . 'includes/class-opb-activator.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-deactivator.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-roles.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-pricing-engine.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-invoice-generator.php';
require_once OPB_PLUGIN_DIR . 'includes/services/class-opb-branch-resolver.php';

// ── Migration engine ──────────────────────────────────────────────────────────
require_once OPB_PLUGIN_DIR . 'includes/migration/class-opb-xlsx-reader.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/class-opb-import-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/class-opb-migration-engine.php';
// Resolvers
require_once OPB_PLUGIN_DIR . 'includes/migration/resolvers/class-opb-kennel-resolver.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/resolvers/class-opb-service-resolver.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/resolvers/class-opb-foodtype-resolver.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/resolvers/class-opb-breed-resolver.php';
// Adapters
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-clients-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-pets-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-bookings-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-invoices-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-payments-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-expenses-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-services-adapter.php';
require_once OPB_PLUGIN_DIR . 'includes/migration/adapters/class-opb-addons-adapter.php';

require_once OPB_PLUGIN_DIR . 'includes/class-opb-customizations.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-onboarding-handler.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-notifications.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-public-portal.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-invoice-document.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-client-auth.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-client-portal.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-rest-base.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-branches-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-clients-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-pets-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-bookings-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-invoices-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-payments-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-tasks-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-expenses-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-settings-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-dashboard-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-import-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-reports-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-kennels-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-public-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-inquiries-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-customizations-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-invoice-delivery-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-health-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-client-relationship-api.php';
require_once OPB_PLUGIN_DIR . 'admin/class-opb-admin-page.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-portal.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-login-branding.php';

register_activation_hook( __FILE__,   [ OPB_Activator::class,   'activate'   ] );
register_deactivation_hook( __FILE__, [ OPB_Deactivator::class, 'deactivate' ] );

add_action( 'init', 'opb_maybe_create_tables' );
add_action( 'init', [ OPB_Roles::class, 'register' ] );

// Portal — registers its own init/filter/action hooks
OPB_Portal::register();

// Public portal — inquiry form & onboarding pages (no auth)
OPB_Public_Portal::register();

// Invoice document public view route (no auth, token-gated)
OPB_Invoice_Document::register();

// Client relationship page /my-pets/ (no WP login — OTP + session)
OPB_Client_Portal::register();

// Login page branding — colour, shadow, typography only; no geometry
OPB_Login_Branding::register();

add_action( 'rest_api_init',         'opb_register_rest_routes'  );
add_action( 'admin_menu',            'opb_register_admin_menu'   );
add_action( 'admin_enqueue_scripts', 'opb_enqueue_admin_assets'  );

function opb_maybe_create_tables(): void {
    if ( get_option( 'opb_db_version' ) !== OPB_VERSION ) {
        OPB_Activator::create_tables();
        update_option( 'opb_db_version', OPB_VERSION );
    }
}

function opb_register_rest_routes(): void {
    ( new OPB_Branches_API()  )->register_routes();
    ( new OPB_Clients_API()   )->register_routes();
    ( new OPB_Pets_API()      )->register_routes();
    ( new OPB_Bookings_API()  )->register_routes();
    ( new OPB_Invoices_API()  )->register_routes();
    ( new OPB_Payments_API()  )->register_routes();
    ( new OPB_Tasks_API()     )->register_routes();
    ( new OPB_Expenses_API()  )->register_routes();
    ( new OPB_Settings_API()  )->register_routes();
    ( new OPB_Dashboard_API() )->register_routes();
    ( new OPB_Import_API()    )->register_routes();
    ( new OPB_Reports_API()   )->register_routes();
    ( new OPB_Kennels_API()   )->register_routes();
    ( new OPB_Public_API()         )->register_routes();
    ( new OPB_Inquiries_API()      )->register_routes();
    ( new OPB_Customizations_API()     )->register_routes();
    ( new OPB_Invoice_Delivery_API()   )->register_routes();
    ( new OPB_Health_API()                  )->register_routes();
    ( new OPB_Client_Relationship_API()     )->register_routes();
}

function opb_register_admin_menu(): void {
    OPB_Admin_Page::register_menu();
}

function opb_enqueue_admin_assets( string $hook ): void {
    if ( ! str_contains( $hook, 'opb' ) ) {
        return;
    }
    OPB_Admin_Page::enqueue_assets();
}
