<?php

declare(strict_types=1);

namespace CoaVault\Data;

use CoaVault\Support\Normalize;

/**
 * Computes the size_token → variation map for a variable product by reading each
 * variation's real attribute values (read-only, no DB writes). This is what makes
 * hybrid binding EXACT — a COA stored under '10mg' resolves to the actual variation
 * whose size attribute is "10mg" / "10 mg" / "10-mg", and lets adapters set a real
 * variation_id when one genuinely matches.
 */
final class SizeAliasBuilder
{
    /** @var array<int,array<string,SizeAlias>> per-product cache */
    private array $cache = [];

    /**
     * @return array<string,SizeAlias> normalized size_token => alias
     */
    public function for_product(int $product_id): array
    {
        if (isset($this->cache[$product_id])) {
            return $this->cache[$product_id];
        }

        $aliases = [];
        if (function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product instanceof \WC_Product && $product->is_type('variable')) {
                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product((int) $variation_id);
                    if (!$variation instanceof \WC_Product) {
                        continue;
                    }
                    foreach ($variation->get_attributes() as $slug => $value) {
                        if ($value === '' || $value === null) {
                            continue;
                        }
                        $token = Normalize::size_token((string) $value);
                        // Only keep attributes that look like a size/weight.
                        if ($token === '' || !preg_match('/\d/', $token)) {
                            continue;
                        }
                        $aliases[$token] = new SizeAlias(
                            $product_id,
                            $token,
                            (int) $variation_id,
                            (string) $slug,
                            (string) $value
                        );
                    }
                }
            }
        }

        return $this->cache[$product_id] = $aliases;
    }

    /** Variation id for a token on a product, or null when there is no exact match. */
    public function variation_for(int $product_id, string $size_token): ?int
    {
        $aliases = $this->for_product($product_id);
        return $aliases[$size_token]->variation_id ?? null;
    }

    /** True when the product has a real variation matching this size token. */
    public function has_size(int $product_id, string $size_token): bool
    {
        return $size_token !== '' && isset($this->for_product($product_id)[$size_token]);
    }
}
