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
        $is_product = $screen && $screen->post_type === 'product' && $screen->base === 'post';
        $is_coa     = str_contains($hook, 'coa-vault');

        if (!$is_product && !$is_coa) {
            return;
        }

        wp_enqueue_style('coa-vault-admin', COA_VAULT_URL . 'assets/css/coa-admin.css', [], COA_VAULT_VERSION);

        if ($is_product) {
            wp_enqueue_media();
            // Bundled QR decoder (jsQR, Apache-2.0) — read in-browser at scan time.
            wp_enqueue_script('coa-vault-jsqr', COA_VAULT_URL . 'assets/js/vendor/jsqr.min.js', [], '1.4.0', true);
            wp_enqueue_script(
                'coa-vault-admin',
                COA_VAULT_URL . 'assets/js/coa-admin.js',
                ['jquery', 'coa-vault-jsqr'],
                COA_VAULT_VERSION,
                true
            );
            wp_localize_script('coa-vault-admin', 'coaAdmin', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(BatchController::NONCE),
                'i18n'    => [
                    'remove'     => __('remove', 'coa-vault'),
                    'name'       => __('name', 'coa-vault'),
                    'value'      => __('value', 'coa-vault'),
                    'unit'       => __('unit', 'coa-vault'),
                    'scanning'     => __('Reading certificate…', 'coa-vault'),
                    'scanDone'     => __('Read — review the fields below before saving.', 'coa-vault'),
                    'scanManual'   => __('File attached and QR read — enter the figures below.', 'coa-vault'),
                    'scanFail'     => __('Could not read that file.', 'coa-vault'),
                    'selectReport' => __('Select certificate', 'coa-vault'),
                ],
            ]);
        }
    }
}
