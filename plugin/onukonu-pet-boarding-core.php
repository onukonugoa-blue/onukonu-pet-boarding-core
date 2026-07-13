<?php
/**
 * Plugin Name: Onukonu Pet Boarding Core
 * Plugin URI:  https://onukonu.com
 * Description: Replacement platform for the discontinued boarding SaaS. Manages clients, pets, bookings, invoices, payments, and operations across three branches.
 * Version:     3.7.0
 * Author:      Onukonu Pet Homestyle Boarding
 * License:     GPL-2.0-or-later
 * Text Domain: opb
 * Requires PHP: 8.2
 * Requires at least: 6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OPB_VERSION',     '3.5.1' );
define( 'OPB_PLUGIN_FILE', __FILE__ );
define( 'OPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'OPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Composer autoloader — must come before any class that depends on vendor packages (e.g. mPDF)
require_once OPB_PLUGIN_DIR . 'vendor/autoload.php';

require_once OPB_PLUGIN_DIR . 'includes/class-opb-activator.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-cron-health.php';
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
require_once OPB_PLUGIN_DIR . 'includes/class-opb-opsmail.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-telegram-consumer.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-mailbox-processor.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-sal-snapshot.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-sal-formatter.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-sal-scheduler.php';
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
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-expense-categories-api.php';
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
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-data-management-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-opsmail-api.php';
require_once OPB_PLUGIN_DIR . 'includes/api/class-opb-sal-api.php';
require_once OPB_PLUGIN_DIR . 'admin/class-opb-admin-page.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-user-admin.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-portal.php';
require_once OPB_PLUGIN_DIR . 'includes/class-opb-login-branding.php';

register_activation_hook( __FILE__,   [ OPB_Activator::class,   'activate'   ] );
register_deactivation_hook( __FILE__, [ OPB_Deactivator::class, 'deactivate' ] );

add_action( 'init', 'opb_maybe_create_tables' );
add_action( 'init', [ OPB_Roles::class, 'register' ] );

// Portal — registers its own init/filter/action hooks
OPB_Portal::register();

// User Admin — WP admin branch-assignment field, validation, and warning panel
if ( is_admin() ) {
    OPB_User_Admin::register();
}

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
    ( new OPB_Expenses_API()            )->register_routes();
    ( new OPB_Expense_Categories_API()  )->register_routes();
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
    ( new OPB_Data_Management_API()        )->register_routes();
    ( new OPB_Opsmail_API()                )->register_routes();
    ( new OPB_SAL_API()                    )->register_routes();
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

// ── SAL v3.1.0: Situational Awareness Layer Cron ─────────────────────────────

add_filter( 'cron_schedules', [ OPB_SAL_Scheduler::class, 'add_schedule' ] );

add_action( 'init', [ OPB_SAL_Scheduler::class, 'maybe_schedule' ] );

add_action( OPB_SAL_Scheduler::CRON_HOOK, 'opb_cron_sal_handler' );
function opb_cron_sal_handler(): void {
    OPB_Cron_Health::record_ping( 'sal' );
    try {
        OPB_SAL_Scheduler::check_and_run();
    } catch ( \Throwable $e ) {
        error_log( '[OPB CRON] SAL handler fatal: ' . $e->getMessage() );
    }
}

// ── OPSMAIL v3.0.0: WP Cron pipeline ─────────────────────────────────────────

/**
 * Register custom cron intervals based on the mailbox_poll_interval setting.
 * Called on 'cron_schedules' filter.
 */
add_filter( 'cron_schedules', 'opb_add_cron_schedules' );
function opb_add_cron_schedules( array $schedules ): array {
    try {
        $minutes = (int) OPB_Customizations::get( 'mailbox_poll_interval' ) ?: 5;
        $minutes = max( 1, min( 60, $minutes ) ); // clamp to 1–60 minutes
        $schedules['opb_mailbox_interval'] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => 'OPB Mailbox Poll (every ' . $minutes . ' min)',
        ];
    } catch ( \Throwable $e ) {
        error_log( '[OPB CRON] opb_add_cron_schedules error: ' . $e->getMessage() );
    }
    // Fixed 1-minute schedule for Telegram consumer
    $schedules['opb_telegram_interval'] = [
        'interval' => MINUTE_IN_SECONDS,
        'display'  => 'OPB Telegram Consumer (every 1 min)',
    ];
    return $schedules;
}

/**
 * Schedule both cron events on init if not already scheduled.
 * Reschedules the mailbox cron if the poll interval setting has changed.
 */
add_action( 'init', 'opb_maybe_schedule_cron' );
function opb_maybe_schedule_cron(): void {
    try {
        // ── Mailbox processor ──────────────────────────────────────────────────
        $minutes          = (int) OPB_Customizations::get( 'mailbox_poll_interval' ) ?: 5;
        $minutes          = max( 1, min( 60, $minutes ) );
        $desired_interval = $minutes * MINUTE_IN_SECONDS;

        $next_mailbox = wp_next_scheduled( 'opb_cron_process_mailbox' );

        // If scheduled with a different interval, clear and reschedule
        if ( $next_mailbox ) {
            $crons = _get_cron_array();
            $found = false;
            foreach ( $crons as $timestamp => $hooks ) {
                if ( isset( $hooks['opb_cron_process_mailbox'] ) ) {
                    $keys = array_keys( $hooks['opb_cron_process_mailbox'] );
                    $key  = reset( $keys );
                    $schedule = $hooks['opb_cron_process_mailbox'][ $key ]['schedule'] ?? '';
                    // If the schedule key is different, reschedule
                    if ( $schedule !== 'opb_mailbox_interval' ) {
                        wp_clear_scheduled_hook( 'opb_cron_process_mailbox' );
                        $next_mailbox = false;
                    }
                    $found = true;
                    break;
                }
            }
        }

        if ( ! $next_mailbox ) {
            wp_schedule_event( time(), 'opb_mailbox_interval', 'opb_cron_process_mailbox' );
        }

        // ── Telegram consumer — runs every minute via opb_telegram_interval ──────
        $next_telegram = wp_next_scheduled( 'opb_cron_process_telegram' );
        if ( $next_telegram ) {
            // If it was scheduled with the wrong (e.g. 'hourly') interval, reschedule
            $crons = _get_cron_array();
            foreach ( $crons as $hooks ) {
                if ( isset( $hooks['opb_cron_process_telegram'] ) ) {
                    $keys     = array_keys( $hooks['opb_cron_process_telegram'] );
                    $key      = reset( $keys );
                    $schedule = $hooks['opb_cron_process_telegram'][ $key ]['schedule'] ?? '';
                    if ( $schedule !== 'opb_telegram_interval' ) {
                        wp_clear_scheduled_hook( 'opb_cron_process_telegram' );
                        $next_telegram = false;
                    }
                    break;
                }
            }
        }
        if ( ! $next_telegram ) {
            wp_schedule_event( time(), 'opb_telegram_interval', 'opb_cron_process_telegram' );
        }

    } catch ( \Throwable $e ) {
        error_log( '[OPB CRON] opb_maybe_schedule_cron error: ' . $e->getMessage() );
    }
}

/**
 * Cron handler: poll IMAP inbox for unstructured emails.
 */
add_action( 'opb_cron_process_mailbox', 'opb_cron_mailbox_handler' );
function opb_cron_mailbox_handler(): void {
    OPB_Cron_Health::record_ping( 'mailbox' );
    try {
        $log = OPB_Mailbox_Processor::process();
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[OPB CRON] Mailbox: ' . wp_json_encode( $log ) );
        }
    } catch ( \Throwable $e ) {
        error_log( '[OPB CRON] opb_cron_mailbox_handler fatal: ' . $e->getMessage() );
    }
}

/**
 * Cron handler: flush pending Telegram deliveries.
 */
add_action( 'opb_cron_process_telegram', 'opb_cron_telegram_handler' );
function opb_cron_telegram_handler(): void {
    OPB_Cron_Health::record_ping( 'queue' );
    try {
        $log = OPB_Telegram_Consumer::process_queue();
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[OPB CRON] Telegram: ' . wp_json_encode( $log ) );
        }
    } catch ( \Throwable $e ) {
        error_log( '[OPB CRON] opb_cron_telegram_handler fatal: ' . $e->getMessage() );
    }
}
