<?php
/**
 * Uninstall handler.
 *
 * Data is PRESERVED by default. Tables are only dropped if the site owner has
 * explicitly opted in via the `coa_vault_drop_data_on_uninstall` option — COA
 * data is compliance-relevant and should never vanish on an accidental delete.
 *
 * The plugin provisions per-site on a network (Installer::on_new_site), so the
 * cleanup runs per-site too — otherwise every secondary blog keeps its tables,
 * options and (critically) its live Anthropic API key after the plugin is removed.
 *
 * @package CoaVault
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove the current site's footprint. The Anthropic API key is a live secret with
 * no compliance reason to survive removal, so it is always purged; the COA tables
 * and the remaining options are dropped only when the owner opted in.
 */
function coa_vault_uninstall_site(): void
{
    delete_option('coa_vault_anthropic_key');
    delete_transient('coa_vault_update_' . md5('beenacle/coa-vault')); // updater release cache

    if (!get_option('coa_vault_drop_data_on_uninstall')) {
        return;
    }

    global $wpdb;
    $tables = [
        $wpdb->prefix . 'coa_vault_records',
        $wpdb->prefix . 'coa_vault_characteristics',
        $wpdb->prefix . 'coa_vault_size_aliases',
    ];
    foreach ($tables as $table) {
        // Table name is built from $wpdb->prefix + a literal — safe to interpolate.
        $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB
    }

    delete_option('coa_vault_db_version');
    delete_option('coa_vault_drop_data_on_uninstall');
    delete_option('coa_vault_frontend');
    delete_option('coa_vault_autoinject');
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $coa_vault_blog_id) {
        switch_to_blog((int) $coa_vault_blog_id);
        coa_vault_uninstall_site();
        restore_current_blog();
    }
} else {
    coa_vault_uninstall_site();
}
