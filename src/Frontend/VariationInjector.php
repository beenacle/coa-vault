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
     * @param array<string,mixed> $data
     */
    public function inject(array $data, \WC_Product $product, \WC_Product $variation): array
    {
        $data['coa'] = ['size' => $this->size_token_for($variation)];
        return $data;
    }

    private function size_token_for(\WC_Product $variation): string
    {
        foreach ($variation->get_attributes() as $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $token = Normalize::size_token((string) $value);
            if ($token !== '' && preg_match('/\d/', $token)) {
                return $token;
            }
        }
        return '';
    }
}
