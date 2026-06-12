<?php
/**
 * Uninstall handler.
 *
 * Data is PRESERVED by default. Tables are only dropped if the site owner has
 * explicitly opted in via the `coa_vault_drop_data_on_uninstall` option — COA
 * data is compliance-relevant and should never vanish on an accidental delete.
 *
 * @package CoaVault
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

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
