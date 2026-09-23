<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

use CoaVault\Support\Normalize;

/**
 * Injects a LIGHTWEIGHT size token per variation into the variations JSON (not
 * pre-rendered HTML — that would bloat the page and break above the AJAX
 * threshold). The frontend JS uses it to lazy-fetch the rendered COA from the
 * REST /resolve endpoint on `found_variation`.
 */
final class VariationInjector
{
    public function register(): void
    {
        add_filter('woocommerce_available_variation', [$this, 'inject'], 100, 3);
    }

    /**
     * No type-hints on purpose: other plugins may legitimately return false from
     * this filter to hide a variation (core array_filters the results), and at
     * priority 100 we receive that filtered value — a hinted signature would fatal.
     *
     * @param array<string,mixed>|false $data
     * @param mixed $product
     * @param mixed $variation
     * @return array<string,mixed>|false
     */
    public function inject($data, $product, $variation)
    {
        if (!is_array($data) || !$variation instanceof \WC_Product) {
            return $data;
        }
        $data['coa'] = ['size' => $this->size_token_for($variation)];
        return $data;
    }

    /**
     * Derive the size token from the variation's attributes without guessing:
     * an attribute NAMED like a size (size/strength/dose/weight) is trusted fully
     * (so a bare "5" still means 5mg, matching how sizes are stored), while any
     * other attribute only counts when its value carries an explicit unit —
     * otherwise "Quantity: 10 vials" would tokenize to 10mg and bind the wrong
     * certificate. No match → '' → the COA falls back to product level, which is
     * always safe.
     */
    private function size_token_for(\WC_Product $variation): string
    {
        return self::size_attribute($variation)[0];
    }

    /**
     * The size token AND the attribute it came from, so the storefront can label a
     * batch with the store's own wording ("25 g", "10 Vials Kit") rather than the
     * normalized matching key. Shared with RenderService so both read sizes the same way.
     *
     * @return array{0:string,1:string} [size_token, attribute name], or ['', ''] when none
     */
    public static function size_attribute(\WC_Product $variation): array
    {
        $attributes = $variation->get_attributes();

        foreach ($attributes as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (!preg_match('/size|strength|dos(e|age)|weight|mg/i', (string) $name)) {
                continue;
            }
            $token = Normalize::size_token($value);
            if ($token !== '' && preg_match('/\d/', $token)) {
                return [$token, (string) $name];
            }
        }

        foreach ($attributes as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (!preg_match('/\d\s*-?\s*(mcg|mg|kg|g|iu|ml|kit)\b/i', $value)) {
                continue;
            }
            $token = Normalize::size_token($value);
            if ($token !== '') {
                return [$token, (string) $name];
            }
        }

        return ['', ''];
    }
}
