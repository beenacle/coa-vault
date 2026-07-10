<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;
use CoaVault\Data\RecordInput;
use CoaVault\Support\Normalize;

/**
 * Applies a reviewed AI backfill from the All-COAs list: the browser re-read each
 * selected record's certificate through the parse-only scan endpoint, the admin
 * ticked the values to keep, and this endpoint writes them.
 *
 * Fill-blanks-only, enforced server-side: every proposed value is dropped unless
 * the record's field is STILL empty at write time (and characteristics are only
 * added when the record has none). The client already offers only blanks, but a
 * stale tab or crafted request can't overwrite good data either.
 */
final class BackfillController
{
    public function __construct(private CoaRepository $records)
    {
    }

    public function register(): void
    {
        add_action('wp_ajax_coa_backfill_apply', [$this, 'apply']);
    }

    public function apply(): void
    {
        if (!check_ajax_referer(BatchController::NONCE, 'nonce', false) || !current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Not allowed.', 'coa-vault')], 403);
        }

        $items = json_decode((string) wp_unslash($_POST['items'] ?? ''), true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if (!is_array($items) || $items === []) {
            wp_send_json_error(['message' => __('Nothing to apply.', 'coa-vault')], 400);
        }

        $applied = 0;
        $skipped = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id  = (int) ($item['id'] ?? 0);
            $rec = $id > 0 ? $this->records->find($id, false) : null;
            if ($rec === null) {
                $skipped++;
                continue;
            }

            $set   = (array) ($item['set'] ?? []);
            $chars = [];
            if ($rec['characteristics'] === []) {
                foreach ((array) ($item['characteristics'] ?? []) as $c) {
                    $row = is_array($c) ? RecordInput::char_row($c) : null;
                    if ($row === null) {
                        continue;
                    }
                    // The AI occasionally reports a headline figure as a characteristic;
                    // fold it into the proposed field (same rule as the save path — the
                    // blank-only check below still applies) instead of storing a row the
                    // hand-save path could never produce.
                    $fold = RecordInput::headline_fold($row);
                    if ($fold !== null) {
                        if (!isset($set[$fold])) {
                            $set[$fold] = $row['value_num'];
                        }
                        continue;
                    }
                    $chars[] = $row;
                }
            }

            $columns = $this->blank_only_columns($rec, $set);

            if ($columns === [] && $chars === []) {
                $skipped++;
                continue;
            }
            $this->records->backfill($id, $columns, $chars);
            $applied++;
        }

        wp_send_json_success(['applied' => $applied, 'skipped' => $skipped]);
    }

    /**
     * Column updates for proposed values whose target field is currently blank —
     * anything else is silently dropped. Normalization mirrors RecordInput so a
     * backfilled value is indistinguishable from a hand-saved one.
     *
     * @param array<string,mixed> $rec Shaped record from CoaRepository::find().
     * @param array<string,mixed> $set field => proposed value, from the review UI.
     * @return array<string,mixed>
     */
    private function blank_only_columns(array $rec, array $set): array
    {
        $columns = [];

        $batch = sanitize_text_field((string) ($set['batch'] ?? ''));
        if ($batch !== '' && $rec['batch'] === '') {
            $columns['batch']          = $batch;
            $columns['batch_inferred'] = 0;
        }

        $lab_label = sanitize_text_field((string) ($set['lab'] ?? ''));
        if ($lab_label !== '' && $rec['lab']['label'] === '') {
            $lab                  = Normalize::lab($lab_label);
            $columns['lab_slug']  = $lab['slug'];
            $columns['lab_label'] = $lab['label'];
        }

        if ($rec['analysis_date'] === null && (string) ($set['date'] ?? '') !== '') {
            [$iso, $ok] = Normalize::date((string) $set['date']);
            if ($ok) {
                $columns['analysis_date'] = $iso;
            }
        }

        $purity = $set['purity'] ?? null;
        if ($rec['purity_pct'] === null && is_numeric($purity) && (float) $purity > 0 && (float) $purity <= 100) {
            $columns['purity_pct'] = (float) $purity;
        }

        $mass = $set['mass'] ?? null;
        if ($rec['mass_mg'] === null && is_numeric($mass) && (float) $mass > 0) {
            $columns['mass_mg'] = (float) $mass;
        }

        $verify = esc_url_raw((string) ($set['verify'] ?? ''));
        if ($verify !== '' && (string) ($rec['report']['verify_url'] ?? '') === '') {
            $columns['verify_url'] = $verify;
        }

        return $columns;
    }

}
