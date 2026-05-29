<?php
/**
 * Fired during plugin deactivation.
 * Note: Tables are intentionally NOT dropped on deactivation to preserve data.
 * Use uninstall.php to remove tables on full plugin deletion.
 */
class OPB_Deactivator {

    public static function deactivate(): void {
        // Flush rewrite rules in case the plugin registered any.
        flush_rewrite_rules();
    }
}
