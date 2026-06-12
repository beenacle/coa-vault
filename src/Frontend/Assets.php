<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

/**
 * Enqueues the frontend swap script + styles on product pages and localizes the
 * REST base + nonce.
 */
final class Assets
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        if (!$this->should_load()) {
            return;
        }

        wp_enqueue_style(
            'coa-vault-frontend',
            COA_VAULT_URL . 'assets/css/coa-frontend.css',
            [],
            COA_VAULT_VERSION
        );

        wp_enqueue_script(
            'coa-vault-frontend',
            COA_VAULT_URL . 'assets/js/coa-frontend.js',
            ['jquery'],
            COA_VAULT_VERSION,
            true
        );

        wp_localize_script('coa-vault-frontend', 'coaVault', [
            'rest'  => esc_url_raw(rest_url('coa-vault/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /** Load on product pages, plus any singular post/page whose content uses [coa_vault]. */
    private function should_load(): bool
    {
        if (function_exists('is_product') && is_product()) {
            return true;
        }
        $post = get_post();
        return $post instanceof \WP_Post && has_shortcode((string) $post->post_content, 'coa_vault');
    }
}
