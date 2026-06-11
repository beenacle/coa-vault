<?php

declare(strict_types=1);

namespace CoaVault\Admin;

/**
 * Adds a "Certificates of Analysis" metabox to the product edit screen. Works for
 * both simple and variable products: each COA carries an optional size token and
 * variation id, so multiple batches per size are managed from one place.
 */
final class ProductPanel
{
    public function __construct(private AdminRenderer $renderer)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_metabox']);
    }

    public function add_metabox(): void
    {
        add_meta_box(
            'coa_vault_panel',
            __('Certificates of Analysis', 'coa-vault'),
            [$this, 'render'],
            'product',
            'normal',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        echo '<div class="coa-admin" id="coa-admin">';
        echo '<div class="coa-admin-list">' . $this->renderer->render_list($post->ID) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo $this->renderer->render_form($post->ID); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div>';
    }
}
