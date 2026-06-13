<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

/**
 * Opt-out auto-placement on the single product page, so sites get COA output with
 * zero template edits. Disable via the `coa_vault_autoinject` option = '0' or the
 * `coa_vault_autoinject` filter.
 */
final class AutoInject
{
    public function __construct(private RenderService $renderer)
    {
    }

    public function register(): void
    {
        add_action('woocommerce_single_product_summary', [$this, 'maybe_render'], 25);
    }

    public function maybe_render(): void
    {
        $enabled = (bool) apply_filters('coa_vault_autoinject', get_option('coa_vault_autoinject', '1') !== '0');
        if (!$enabled) {
            return;
        }
        global $product;
        if (!$product instanceof \WC_Product) {
            return;
        }
        // Defer to manual placement: if the page already places a panel via the
        // [coa_vault] shortcode or the coa-vault/panel block (e.g. in the product
        // content/tabs, which render AFTER this summary hook), don't auto-inject too.
        $post = get_post();
        if ($post instanceof \WP_Post
            && (has_shortcode((string) $post->post_content, 'coa_vault') || has_block('coa-vault/panel', $post))) {
            return;
        }
        // Belt-and-suspenders for a panel rendered BEFORE this hook (e.g. the short
        // description): render_for_product() fires `coa_vault_rendered`.
        if (did_action('coa_vault_rendered')) {
            return;
        }
        echo $this->renderer->render_for_product($product->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
