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
        // Avoid double output if a block/shortcode already placed it in this request.
        if (did_action('coa_vault_rendered')) {
            return;
        }
        do_action('coa_vault_rendered');
        echo $this->renderer->render_for_product($product->get_id()); // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
