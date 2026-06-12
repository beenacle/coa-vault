<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

/**
 * The single `[coa_vault]` shortcode for classic themes / page builders.
 *   [coa_vault]                — COAs for the current product
 *   [coa_vault product_id="N"] — COAs for a specific product
 *   [coa_vault all="true"]     — every published product's COAs (catalog archive)
 */
final class Shortcode
{
    public function __construct(private RenderService $renderer)
    {
    }

    public function register(): void
    {
        add_shortcode('coa_vault', [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(['product_id' => 0, 'all' => ''], (array) $atts, 'coa_vault');

        if (filter_var($atts['all'], FILTER_VALIDATE_BOOLEAN)) {
            return $this->renderer->render_all_products();
        }

        $product_id = (int) $atts['product_id'];

        if ($product_id === 0) {
            global $product;
            if ($product instanceof \WC_Product) {
                $product_id = $product->get_id();
            } elseif (is_singular('product')) {
                $product_id = get_the_ID();
            }
        }
        if ($product_id === 0) {
            return '';
        }

        return $this->renderer->render_for_product($product_id);
    }
}
