<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;
use CoaVault\Data\RecordInput;

/**
 * AJAX save/delete for COA batches from the product metabox. Nonce + cap guarded.
 * Both actions return the freshly rendered list so the UI updates in place.
 */
final class BatchController
{
    public const NONCE = 'coa_vault_admin';

    public function __construct(
        private CoaRepository $records,
        private AdminRenderer $renderer,
    ) {
    }

    public function register(): void
    {
        add_action('wp_ajax_coa_save_batch', [$this, 'save']);
        add_action('wp_ajax_coa_delete_batch', [$this, 'delete']);
    }

    public function save(): void
    {
        $this->guard();

        $input = (array) wp_unslash($_POST['coa'] ?? []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        [$columns, $chars] = RecordInput::to_columns($input);

        if ($columns['product_id'] <= 0) {
            wp_send_json_error(['message' => __('Missing product.', 'coa-vault')], 400);
        }

        $id = isset($input['id']) && $input['id'] !== '' ? (int) $input['id'] : null;
        if ($id !== null) {
            unset($columns['product_id']); // immutable on edit
        }
        $this->records->save_from_admin($id, $columns, $chars);

        wp_send_json_success([
            'list_html' => $this->renderer->render_list((int) $input['product_id']),
        ]);
    }

    public function delete(): void
    {
        $this->guard();

        $id         = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;
        if ($id > 0) {
            $this->records->delete($id);
        }

        wp_send_json_success([
            'list_html' => $this->renderer->render_list($product_id),
        ]);
    }

    private function guard(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false) || !current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Not allowed.', 'coa-vault')], 403);
        }
    }
}
