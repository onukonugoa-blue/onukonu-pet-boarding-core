<?php
/**
 * Fired during plugin deactivation.
 * Note: Tables are intentionally NOT dropped on deactivation to preserve data.
 * Use uninstall.php to remove tables on full plugin deletion.
 */
class OPB_Deactivator {

    public static function deactivate(): void {
        // ── Clear all OPB scheduled cron events ───────────────────────────────
        // SAL hourly check
        wp_clear_scheduled_hook( 'opb_cron_sal_check' );
        // OPSMAIL queue consumers
        wp_clear_scheduled_hook( 'opb_cron_process_mailbox' );
        wp_clear_scheduled_hook( 'opb_cron_process_telegram' );

        // Flush rewrite rules in case the plugin registered any.
        flush_rewrite_rules();
    }
}
