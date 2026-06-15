<?php

declare(strict_types=1);

namespace CoaVault\Admin;

/**
 * Native Settings API page (COA → Settings). Surfaces the handful of plugin
 * options that until now existed only as DB flags / filters: storefront display,
 * automatic product-page placement, and the destructive uninstall cleanup.
 *
 * Stored values stay the exact '1'/'0' strings the frontend already reads, so
 * this is purely a UI over existing options — how they're consumed is unchanged.
 * The option group capability defaults to `manage_options`, matching the page,
 * so a shop manager who can enter COAs can't flip plugin-wide switches.
 */
final class Settings
{
    private const GROUP = 'coa_vault_settings';
    private const PAGE  = 'coa-vault-settings';

    /** Option holding the Anthropic API key (used only when the constant is absent). */
    public const KEY_OPTION = 'coa_vault_anthropic_key';

    /**
     * The Anthropic API key for AI certificate reading: a wp-config.php constant
     * takes precedence (keeps the secret out of the database), else the stored option.
     */
    public static function anthropic_key(): string
    {
        if (defined('COA_VAULT_ANTHROPIC_KEY') && (string) COA_VAULT_ANTHROPIC_KEY !== '') {
            return (string) COA_VAULT_ANTHROPIC_KEY;
        }
        return (string) get_option(self::KEY_OPTION, '');
    }

    /** AI extraction is available only when a key is configured (opt-in, harmless when unset). */
    public static function ai_enabled(): bool
    {
        return self::anthropic_key() !== '';
    }

    private static function key_from_constant(): bool
    {
        return defined('COA_VAULT_ANTHROPIC_KEY') && (string) COA_VAULT_ANTHROPIC_KEY !== '';
    }

    /**
     * A masked preview of the stored key for the settings field — confirms a key is
     * saved (and which one, via its tail) without exposing the secret. Keeps a short
     * leading slice and the last 4 chars, like a dashboard key display.
     */
    private static function mask_key(string $key): string
    {
        $len = strlen($key);
        if ($len <= 10) {
            return str_repeat('*', 10); // too short to reveal any portion safely
        }
        return substr($key, 0, 7) . str_repeat('*', 10) . substr($key, -4);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_page(): void
    {
        add_submenu_page(
            'coa-vault',
            __('COA Settings', 'coa-vault'),
            __('Settings', 'coa-vault'),
            'manage_options',
            self::PAGE,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        $bool = [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_bool'],
        ];

        register_setting(self::GROUP, 'coa_vault_frontend', $bool + ['default' => '1']);
        register_setting(self::GROUP, 'coa_vault_autoinject', $bool + ['default' => '1']);
        register_setting(self::GROUP, 'coa_vault_drop_data_on_uninstall', $bool + ['default' => '0']);
        register_setting(self::GROUP, self::KEY_OPTION, [
            'type'              => 'string',
            'sanitize_callback' => [self::class, 'sanitize_key'],
            'default'           => '',
        ]);

        add_settings_section(
            'coa_vault_display',
            __('Display', 'coa-vault'),
            [$this, 'display_intro'],
            self::PAGE
        );
        add_settings_field(
            'coa_vault_frontend',
            __('Storefront display', 'coa-vault'),
            [$this, 'field_frontend'],
            self::PAGE,
            'coa_vault_display'
        );
        add_settings_field(
            'coa_vault_autoinject',
            __('Automatic placement', 'coa-vault'),
            [$this, 'field_autoinject'],
            self::PAGE,
            'coa_vault_display'
        );

        add_settings_section(
            'coa_vault_data',
            __('Data', 'coa-vault'),
            '__return_null', // no section intro; the field carries its own description
            self::PAGE
        );
        add_settings_field(
            'coa_vault_drop_data_on_uninstall',
            __('On uninstall', 'coa-vault'),
            [$this, 'field_uninstall'],
            self::PAGE,
            'coa_vault_data'
        );

        add_settings_section(
            'coa_vault_ai',
            __('AI extraction', 'coa-vault'),
            [$this, 'ai_intro'],
            self::PAGE
        );
        add_settings_field(
            self::KEY_OPTION,
            __('Anthropic API key', 'coa-vault'),
            [$this, 'field_key'],
            self::PAGE,
            'coa_vault_ai'
        );
    }

    /**
     * Preserve the stored key when the password field is submitted blank (the field
     * renders empty so the secret is never echoed back to the page). A non-empty
     * submit replaces it.
     *
     * @param mixed $value
     */
    public static function sanitize_key($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return (string) get_option(self::KEY_OPTION, '');
        }
        return sanitize_text_field($value);
    }

    /**
     * Coerce a checkbox value to the stored '1'/'0' string. An unchecked box is
     * absent from POST, so options.php passes null here — which becomes '0'.
     *
     * @param mixed $value
     */
    public static function sanitize_bool($value): string
    {
        return empty($value) || $value === '0' ? '0' : '1';
    }

    public function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('COA Settings', 'coa-vault') . '</h1>';
        echo '<form action="options.php" method="post">';
        settings_fields(self::GROUP);
        do_settings_sections(self::PAGE);
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function display_intro(): void
    {
        echo '<p>' . wp_kses(
            sprintf(
                /* translators: 1: [coa_vault] shortcode, 2: archive shortcode, 3: block name */
                __('Place a certificate panel anywhere with the %1$s shortcode or the %3$s block; list every product&#8217;s COAs with %2$s.', 'coa-vault'),
                '<code>[coa_vault]</code>',
                '<code>[coa_vault all="true"]</code>',
                '<strong>' . esc_html__('COA Vault Panel', 'coa-vault') . '</strong>'
            ),
            ['code' => [], 'strong' => []]
        ) . '</p>';
    }

    public function field_frontend(): void
    {
        $on = get_option('coa_vault_frontend', '1') !== '0';
        echo '<label><input type="checkbox" name="coa_vault_frontend" value="1"' . checked($on, true, false) . '> '
            . esc_html__('Let the plugin display certificates on the storefront', 'coa-vault') . '</label>';
        echo '<p class="description">' . esc_html__('Master switch for the plugin’s built-in output — the [coa_vault] shortcode, the COA block, and automatic placement (below). Turn it off to hide all of that and supply your own markup instead (e.g. a page-builder template); the REST API and stored data stay available either way.', 'coa-vault') . '</p>';
    }

    public function field_autoinject(): void
    {
        $on = get_option('coa_vault_autoinject', '1') !== '0';
        echo '<label><input type="checkbox" name="coa_vault_autoinject" value="1"' . checked($on, true, false) . '> '
            . esc_html__('Show certificates automatically on product pages', 'coa-vault') . '</label>';
        echo '<p class="description">' . esc_html__('Adds a certificate panel below the product summary, with no setup. Turn off to place COAs yourself with the [coa_vault] shortcode or the COA block instead. Requires “Storefront display” (above).', 'coa-vault') . '</p>';
    }

    public function field_uninstall(): void
    {
        $on = (bool) get_option('coa_vault_drop_data_on_uninstall');
        echo '<label><input type="checkbox" name="coa_vault_drop_data_on_uninstall" value="1"' . checked($on, true, false) . '> '
            . esc_html__('Delete all COA data when the plugin is deleted', 'coa-vault') . '</label>';
        echo '<p class="description">' . esc_html__('Off by default, so deleting the plugin keeps your certificates and nothing is lost by accident. Tick this only if you want the COA tables removed on uninstall.', 'coa-vault') . '</p>';
    }

    public function ai_intro(): void
    {
        echo '<p>' . esc_html__('Optional. With a key set, the “Scan / Import COA” button on a product reads the certificate image or PDF and pre-fills the batch, lab, purity, mass and date for you to review. Without a key, scanning still attaches the file and reads the QR verify link — you fill the numbers in yourself.', 'coa-vault') . '</p>';
    }

    public function field_key(): void
    {
        if (self::key_from_constant()) {
            echo '<p class="description">' . esc_html__('Configured via the COA_VAULT_ANTHROPIC_KEY constant in wp-config.php.', 'coa-vault') . '</p>';
            return;
        }
        $key    = (string) get_option(self::KEY_OPTION, '');
        $stored = $key !== '';
        printf(
            '<input type="password" name="%s" value="" autocomplete="off" class="regular-text" placeholder="%s">',
            esc_attr(self::KEY_OPTION),
            $stored ? esc_attr(self::mask_key($key)) : 'sk-ant-...'
        );
        if ($stored) {
            echo '<p class="description">' . esc_html__('A key is saved (shown masked in the field). Leave it blank to keep that key, or paste a new key to replace it.', 'coa-vault') . '</p>';
        }
        echo '<p class="description">' . esc_html__('Used only for the scan feature; sub-cent per certificate. Stored in the database — for stronger secrecy define COA_VAULT_ANTHROPIC_KEY in wp-config.php instead. Certificates are public lab documents, not customer data.', 'coa-vault') . '</p>';
    }
}
