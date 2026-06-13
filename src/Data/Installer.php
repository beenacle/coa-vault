<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * Table provisioning + schema versioning. Handles single-site activation,
 * plugin-update upgrades (without re-activation), and multisite new-blog creation.
 */
final class Installer
{
    /** register_activation_hook callback. */
    public static function activate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            foreach (get_sites(['fields' => 'ids']) as $blog_id) {
                switch_to_blog((int) $blog_id);
                self::install();
                restore_current_blog();
            }
            return;
        }
        self::install();
    }

    /** wp_initialize_site callback — provision a freshly created network site. */
    public static function on_new_site(\WP_Site $site): void
    {
        // is_plugin_active_for_network() lives in an admin include that isn't always
        // loaded when a site is created programmatically (front-end signup, WP-CLI).
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!is_plugin_active_for_network(plugin_basename(COA_VAULT_FILE))) {
            return;
        }
        switch_to_blog((int) $site->blog_id);
        self::install();
        restore_current_blog();
    }

    /** Run on every boot; cheap no-op once the stored version matches the code. */
    public static function maybe_upgrade(): void
    {
        if (get_option('coa_vault_db_version') !== COA_VAULT_DB_VERSION) {
            self::install();
        }
    }

    private static function install(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (Schema::ddl() as $statement) {
            dbDelta($statement);
        }

        update_option('coa_vault_db_version', COA_VAULT_DB_VERSION, false);
    }
}
