<?php
/**
 * Plugin Name: Onukonu Pet Boarding Core
 * Plugin URI:  https://onukonu.com
 * Description: Replacement platform for the discontinued boarding SaaS. Manages clients, pets, bookings, invoices, payments, and operations across three branches.
 * Version:     1.0.0
 * Author:      Onukonu Pet Homestyle Boarding
 * License:     GPL-2.0-or-later
 * Text Domain: opb
 * Requires PHP: 8.2
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OPB_VERSION',     '1.0.0' );
define( 'OPB_PLUGIN_FILE', __FILE__ );
define( 'OPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'OPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Activation / deactivation hooks
register_activation_hook( __FILE__,   [ 'OPB_Activator',   'activate'   ] );
register_deactivation_hook( __FILE__, [ 'OPB_Deactivator', 'deactivate' ] );

// Autoload includes
foreach ( [
    'includes/class-opb-activator.php',
    'includes/class-opb-deactivator.php',
    'includes/class-opb-loader.php',
] as $file ) {
    require_once OPB_PLUGIN_DIR . $file;
}

/**
 * Begin plugin execution.
 */
function opb_run(): void {
    $loader = new OPB_Loader();
    $loader->run();
}
opb_run();
