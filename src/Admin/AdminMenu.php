<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;
use CoaVault\Support\Vocab;

/**
 * Top-level "COA" admin menu → catalog-wide list table. (The Migration submenu is
 * added by the separate "COA Vault — Migration" companion plugin when present.)
 */
final class AdminMenu
{
    public function __construct(private CoaRepository $records)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_menu_page(
            __('Certificates of Analysis', 'coa-vault'),
            __('COA', 'coa-vault'),
            'edit_products',
            'coa-vault',
            [$this, 'render_list_page'],
            'dashicons-clipboard',
            56
        );
    }

    public function render_list_page(): void
    {
        $table = new ListTable($this->records);
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__('Certificates of Analysis', 'coa-vault') . '</h1>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="coa-vault">';
        $current = isset($_GET['lab']) ? sanitize_text_field((string) wp_unslash($_GET['lab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<p><label>' . esc_html__('Filter by lab', 'coa-vault') . ' <select name="lab" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html__('All labs', 'coa-vault') . '</option>';
        // Standard labs + any custom lab in use, so custom labs are filterable too.
        $labs = Vocab::LABS;
        foreach ($this->records->distinct_labs() as $slug => $label) {
            if (!isset($labs[$slug])) {
                $labs[$slug] = $label;
            }
        }
        foreach ($labs as $slug => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($slug), selected($current, $slug, false), esc_html($label));
        }
        echo '</select></label></p>';
        $table->display();
        echo '</form></div>';
    }
}
