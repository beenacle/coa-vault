<?php
/**
 * Plugin Name:          COA Vault
 * Plugin URI:           https://beenacle.com/coa-vault
 * Description:          Unified Certificate of Analysis (COA) management for WooCommerce — custom-table storage, simple + variable product support, multi-COA per size/variation, and auto-migration from legacy ACF / native COA schemas.
 * Version:              0.1.2
 * Requires PHP:         8.1
 * Requires at least:    6.4
 * WC requires at least: 8.0
 * Author:               Beenacle
 * Author URI:           https://beenacle.com
 * Text Domain:          coa-vault
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:           https://github.com/beenacle/coa-vault
 * Tested up to:         6.8
 *
 * @package CoaVault
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('COA_VAULT_VERSION', '0.1.2');
define('COA_VAULT_DB_VERSION', '2');
define('COA_VAULT_FILE', __FILE__);
define('COA_VAULT_PATH', plugin_dir_path(__FILE__));
define('COA_VAULT_URL', plugin_dir_url(__FILE__));

/**
 * Minimal PSR-4 autoloader for the CoaVault\ namespace → src/.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'CoaVault\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path     = COA_VAULT_PATH . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require $path;
    }
});

// Declare WooCommerce HPOS (custom order tables) compatibility. COA Vault never
// touches orders, so this is trivially true — but the declaration is required or
// WooCommerce flags the plugin incompatible and disables HPOS.
add_action('before_woocommerce_init', static function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            COA_VAULT_FILE,
            true
        );
    }
});

// Create/upgrade tables on activation (multisite-aware).
register_activation_hook(__FILE__, [\CoaVault\Data\Installer::class, 'activate']);

// New sites in a multisite network self-provision their tables.
add_action('wp_initialize_site', [\CoaVault\Data\Installer::class, 'on_new_site'], 20, 1);

// Boot the plugin once all plugins (incl. WooCommerce) are loaded.
add_action('plugins_loaded', static function (): void {
    \CoaVault\Plugin::instance()->boot();
}, 20);
