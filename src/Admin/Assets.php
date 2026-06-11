<?php

declare(strict_types=1);

namespace CoaVault\Admin;

/**
 * Enqueues the admin script (media modal + AJAX batch editor) on the product edit
 * screen and the COA admin pages.
 */
final class Assets
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        $screen     = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_product = $screen && $screen->post_type === 'product' && in_array($screen->base, ['post', 'edit'], true) && $screen->base === 'post';
        $is_coa     = str_contains($hook, 'coa-vault');

        if (!$is_product && !$is_coa) {
            return;
        }

        wp_enqueue_style('coa-vault-admin', COA_VAULT_URL . 'assets/css/coa-admin.css', [], COA_VAULT_VERSION);

        if ($is_product) {
            wp_enqueue_media();
            wp_enqueue_script(
                'coa-vault-admin',
                COA_VAULT_URL . 'assets/js/coa-admin.js',
                ['jquery'],
                COA_VAULT_VERSION,
                true
            );
            wp_localize_script('coa-vault-admin', 'coaAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(BatchController::NONCE),
                'i18n'    => [
                    'remove' => __('remove', 'coa-vault'),
                    'name'   => __('name', 'coa-vault'),
                    'value'  => __('value', 'coa-vault'),
                    'unit'   => __('unit', 'coa-vault'),
                ],
            ]);
        }
    }
}
