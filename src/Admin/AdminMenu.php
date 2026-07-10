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

        // The first submenu auto-mirrors the top-level "COA" label, which reads
        // oddly ("COA → COA"). Re-register it with the same slug to relabel just
        // that item — the catalog-wide list — without touching the top-level menu.
        add_submenu_page(
            'coa-vault',
            __('Certificates of Analysis', 'coa-vault'),
            __('All COAs', 'coa-vault'),
            'edit_products',
            'coa-vault',
            [$this, 'render_list_page']
        );
    }

    public function render_list_page(): void
    {
        $table = new ListTable($this->records);
        $table->prepare_items();

        echo '<div class="wrap"><h1>' . esc_html__('Certificates of Analysis', 'coa-vault') . '</h1>';
        $this->render_backfill_notice();
        // The bulk "Read data with AI" flow (coa-list.js) renders its progress +
        // review panel here, above the table.
        echo '<div id="coa-backfill-root"></div>';
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

    /**
     * Nudge toward the bulk backfill when records have a certificate attached but
     * no figures (typical after a legacy migration, which only carried the file).
     * Self-healing: once the data is filled the count drops and the notice goes.
     */
    private function render_backfill_notice(): void
    {
        $count = $this->records->count_backfillable();
        if ($count === 0) {
            return;
        }

        if (!Settings::ai_enabled()) {
            printf(
                '<div class="notice notice-warning inline"><p>%s</p></div>',
                sprintf(
                    /* translators: 1: number of COA records, 2: settings page URL */
                    esc_html__('%1$d COAs have an attached report but missing figures. Add an Anthropic key in %2$s to read them with AI.', 'coa-vault'),
                    (int) $count,
                    '<a href="' . esc_url(admin_url('admin.php?page=coa-vault-settings')) . '">' . esc_html__('COA → Settings', 'coa-vault') . '</a>'
                )
            );
            return;
        }

        printf(
            '<div class="notice notice-info inline"><p>%s</p></div>',
            sprintf(
                /* translators: %d: number of COA records */
                esc_html(_n(
                    '%d COA has an attached report but missing figures. Select it below and run the “Read data with AI” bulk action — you review every value before it is saved.',
                    '%d COAs have an attached report but missing figures. Select them below and run the “Read data with AI” bulk action — you review every value before it is saved.',
                    $count,
                    'coa-vault'
                )),
                (int) $count
            )
        );
    }
}
