<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

/**
 * `[coa]` shortcode (short & friendly) for classic themes / page builders, with a
 * branded `[coa_vault]` alias and a `[cf_coa]` back-compat alias so triumphant sites
 * keep working with no template edits.
 */
final class Shortcode
{
    public function __construct(private RenderService $renderer)
    {
    }

    public function register(): void
    {
        add_shortcode('coa', [$this, 'render']);
        add_shortcode('coa_vault', [$this, 'render']); // branded alias
        add_shortcode('cf_coa', [$this, 'render']);    // legacy alias (triumphant)
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts = []): string
    {
        $atts       = shortcode_atts(['product_id' => 0], (array) $atts, 'coa');
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
