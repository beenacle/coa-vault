<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

/**
 * Server-rendered Gutenberg block `coa-vault/panel` for block-theme product templates.
 * Renders through the shared RenderService, so it matches the shortcode + REST.
 */
final class Block
{
    public function __construct(private RenderService $renderer)
    {
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_block']);
    }

    public function register_block(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        register_block_type(COA_VAULT_PATH . 'blocks/coa-panel', [
            'render_callback' => [$this, 'render'],
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function render(array $attributes = []): string
    {
        $product_id = (int) ($attributes['productId'] ?? 0);
        if ($product_id === 0) {
            $product_id = $this->renderer->context_product_id();
        }
        if ($product_id === 0) {
            return '';
        }
        return $this->renderer->render_for_product($product_id);
    }
}
