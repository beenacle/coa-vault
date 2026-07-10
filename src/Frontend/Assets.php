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
        // Always REGISTER (cheap), so RenderService can late-enqueue by handle when
        // a panel is placed somewhere should_load() can't see (builder widget,
        // template part). Only eagerly ENQUEUE where placement is detectable.
        wp_register_style(
            'coa-vault-frontend',
            COA_VAULT_URL . 'assets/css/coa-frontend.css',
            [],
            COA_VAULT_VERSION
        );

        wp_register_script(
            'coa-vault-frontend',
            COA_VAULT_URL . 'assets/js/coa-frontend.js',
            ['jquery'],
            COA_VAULT_VERSION,
            true
        );

        // The nonce keeps logged-in editors' draft-preview working on the REST swap;
        // the JS retries anonymously when a page cache serves it stale.
        wp_localize_script('coa-vault-frontend', 'coaVault', [
            'rest'  => esc_url_raw(rest_url('coa-vault/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);

        if ($this->should_load()) {
            wp_enqueue_style('coa-vault-frontend');
            wp_enqueue_script('coa-vault-frontend');
        }
    }

    /** Load on product pages, plus any singular post/page using the shortcode or the block. */
    private function should_load(): bool
    {
        if (function_exists('is_product') && is_product()) {
            return true;
        }
        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }
        // Also cover the `coa-vault/panel` block so its viewScript handle is registered
        // and the REST base/nonce are localized when the block is used off product pages.
        return has_shortcode((string) $post->post_content, 'coa_vault')
            || has_block('coa-vault/panel', $post);
    }
}
