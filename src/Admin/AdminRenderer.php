<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;
use CoaVault\Data\SizeAliasBuilder;
use CoaVault\Support\Vocab;

/**
 * Renders the admin COA list + the add/edit form. Shared by the product metabox
 * and the AJAX controller (which returns a fresh list after each write).
 */
final class AdminRenderer
{
    public function __construct(
        private CoaRepository $records,
        private SizeAliasBuilder $aliases,
    ) {
    }

    public function render_list(int $product_id): string
    {
        $records = $this->records->find_by_product($product_id);
        if ($records === []) {
            return '<p class="coa-admin-empty">' . esc_html__('No COA batches yet.', 'coa-vault') . '</p>';
        }

        $rows = '';
        foreach ($records as $r) {
            $report = $r['report']['url'] !== ''
                ? '<a href="' . esc_url($r['report']['url']) . '" target="_blank" rel="noopener">' . esc_html(ucfirst((string) $r['report']['kind'])) . '</a>'
                : '—';
            if (!empty($r['report']['verify_url'])) {
                $report .= ' · <a href="' . esc_url((string) $r['report']['verify_url']) . '" target="_blank" rel="noopener">' . esc_html__('verify', 'coa-vault') . '</a>';
            }
            $size  = $r['size_token'] !== '' ? esc_html($r['size_token']) : ($r['applies_all_sizes'] ? esc_html__('All sizes', 'coa-vault') : '—');
            $latest = $r['is_latest'] ? ' <span class="coa-admin-latest">' . esc_html__('latest', 'coa-vault') . '</span>' : '';

            $rows .= sprintf(
                '<tr data-record="%s">
                    <td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>
                    <td><button type="button" class="button-link coa-edit">%s</button> | <button type="button" class="button-link coa-delete" data-id="%d">%s</button></td>
                </tr>',
                esc_attr((string) wp_json_encode($r)),
                $size,
                $latest,
                esc_html($r['batch'] !== '' ? $r['batch'] : '—'),
                esc_html($r['lab']['label'] !== '' ? $r['lab']['label'] : '—'),
                esc_html((string) ($r['analysis_date'] ?? '—')),
                $r['purity_pct'] !== null ? esc_html((string) $r['purity_pct']) . '%' : '—',
                $r['mass_mg'] !== null ? esc_html((string) $r['mass_mg']) . 'mg' : '—',
                $report,
                esc_html__('Edit', 'coa-vault'),
                (int) $r['id'],
                esc_html__('Delete', 'coa-vault')
            );
        }

        return '<table class="widefat striped coa-admin-table">
            <thead><tr>
                <th>' . esc_html__('Size', 'coa-vault') . '</th>
                <th>' . esc_html__('Batch', 'coa-vault') . '</th>
                <th>' . esc_html__('Lab', 'coa-vault') . '</th>
                <th>' . esc_html__('Date', 'coa-vault') . '</th>
                <th>' . esc_html__('Purity', 'coa-vault') . '</th>
                <th>' . esc_html__('Mass', 'coa-vault') . '</th>
                <th>' . esc_html__('Report', 'coa-vault') . '</th>
                <th>' . esc_html__('Actions', 'coa-vault') . '</th>
            </tr></thead>
            <tbody>' . $rows . '</tbody>
        </table>';
    }

    public function render_form(int $product_id): string
    {
        // Lab is a free-text field with suggestions (a <datalist>): the standard labs
        // PLUS any custom lab already used anywhere (self-extending) — so a lab typed
        // once shows up next time. Admins can still type a brand-new one.
        $lab_names = array_values(Vocab::LABS);
        foreach ($this->records->distinct_labs() as $label) {
            if ($label !== '' && !in_array($label, $lab_names, true)) {
                $lab_names[] = $label;
            }
        }
        $labs = '';
        foreach ($lab_names as $label) {
            $labs .= sprintf('<option value="%s">', esc_attr($label));
        }

        // "Applies to" options, built from the product's REAL variations so admins
        // pick a size instead of typing a token or hunting a variation id. Each
        // option carries its variation id; selecting it fills both behind the scenes.
        // Simple products (no size variations) get only the whole-product option.
        $sizes = '<option value="" data-variation-id="">' . esc_html__('Whole product (all sizes)', 'coa-vault') . '</option>';
        foreach ($this->aliases->for_product($product_id) as $token => $alias) {
            $label  = $alias->term_value !== '' ? $alias->term_value : $token;
            $sizes .= sprintf(
                '<option value="%s" data-variation-id="%s">%s</option>',
                esc_attr($token),
                esc_attr((string) ($alias->variation_id ?? '')),
                esc_html($label)
            );
        }

        return '<div class="coa-admin-form" data-product-id="' . esc_attr((string) $product_id) . '">
            <h4 class="coa-admin-form-title">' . esc_html__('Add / edit COA batch', 'coa-vault') . '</h4>
            <input type="hidden" class="coa-f-id" value="">
            <input type="hidden" class="coa-f-variation" value="">
            <p>
                <label>' . esc_html__('Applies to', 'coa-vault') . ' <select class="coa-f-size-select">' . $sizes . '</select></label>
                <label>' . esc_html__('Batch', 'coa-vault') . ' <input type="text" class="coa-f-batch"></label>
                <label>' . esc_html__('Lab', 'coa-vault') . ' <input type="text" class="coa-f-lab" list="coa-vault-labs" placeholder="' . esc_attr__('Type or pick a lab', 'coa-vault') . '"><datalist id="coa-vault-labs">' . $labs . '</datalist></label>
                <label>' . esc_html__('Date', 'coa-vault') . ' <input type="date" class="coa-f-date"></label>
            </p>
            <p>
                <label>' . esc_html__('Purity %', 'coa-vault') . ' <input type="number" step="0.0001" class="coa-f-purity"></label>
                <label>' . esc_html__('Mass mg', 'coa-vault') . ' <input type="number" step="0.0001" class="coa-f-mass"></label>
            </p>
            <p class="coa-f-report-row">
                <input type="hidden" class="coa-f-fileid" value="">
                <button type="button" class="button coa-pick-media">' . esc_html__('Select report image/PDF', 'coa-vault') . '</button>
                <span class="coa-f-filename"></span>
                <label>' . esc_html__('or Report URL', 'coa-vault') . ' <input type="url" class="coa-f-url" placeholder="https://"></label>
            </p>
            <p class="coa-f-report-row">
                <label>' . esc_html__('Verify / source link', 'coa-vault') . ' <input type="url" class="coa-f-verify" placeholder="https://janoshik.com/... or chromate.org/verify?..."></label>
            </p>
            <div class="coa-f-chars">
                <strong>' . esc_html__('Extra characteristics', 'coa-vault') . '</strong>
                <div class="coa-f-chars-rows"></div>
                <button type="button" class="button coa-add-char">' . esc_html__('+ Add characteristic', 'coa-vault') . '</button>
            </div>
            <p class="coa-f-actions">
                <button type="button" class="button button-primary coa-save">' . esc_html__('Save batch', 'coa-vault') . '</button>
                <button type="button" class="button coa-cancel">' . esc_html__('Clear', 'coa-vault') . '</button>
                <span class="spinner"></span>
            </p>
        </div>';
    }
}
