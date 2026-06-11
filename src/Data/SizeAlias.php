<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * Maps a product's normalized size token to a real WooCommerce variation/term,
 * so hybrid binding matches exactly even when legacy vocab ("10mg") differs from
 * the Woo attribute term slug ("10-mg").
 */
final class SizeAlias
{
    public function __construct(
        public int $product_id,
        public string $size_token,
        public ?int $variation_id = null,
        public string $attribute_slug = '',
        public string $term_value = '',
    ) {
    }
}
