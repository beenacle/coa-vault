<?php

declare(strict_types=1);

namespace CoaVault\Data;

use CoaVault\Support\Normalize;
use CoaVault\Support\Report;
use CoaVault\Support\Vocab;

/**
 * Maps untrusted input (admin form / REST body) to sanitized record columns +
 * characteristics, applying the same normalization as the migration. Shared by
 * the admin AJAX controller and the REST write endpoints so writes are consistent.
 */
final class RecordInput
{
    /**
     * @param array<string,mixed> $in
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>} [columns, characteristics]
     */
    public static function to_columns(array $in): array
    {
        $size = Normalize::size_token((string) ($in['size_token'] ?? ''));

        // Lab: prefer an explicit controlled-vocab slug, else map free text.
        $lab_slug  = (string) ($in['lab_slug'] ?? '');
        $lab_label = (string) ($in['lab_label'] ?? '');
        if ($lab_slug !== '' && isset(Vocab::LABS[$lab_slug])) {
            $lab_label = Vocab::LABS[$lab_slug];
        } elseif ($lab_label !== '') {
            $lab       = Normalize::lab($lab_label);
            $lab_slug  = $lab['slug'];
            $lab_label = $lab['label'];
        }

        [$iso, $ok] = Normalize::date((string) ($in['analysis_date'] ?? ''));

        $file_id  = isset($in['report_file_id']) && $in['report_file_id'] !== '' ? (int) $in['report_file_id'] : null;
        $resolved = Report::resolve($file_id, (string) ($in['report_url'] ?? ''), (string) ($in['report_kind'] ?? Report::KIND_IMAGE));

        $chars  = [];
        $purity = null;
        $mass   = null;
        foreach ((array) ($in['characteristics'] ?? []) as $c) {
            $row = is_array($c) ? self::char_row($c) : null;
            if ($row === null) {
                continue;
            }

            // A "Purity"/"Mass" repeater row IS the headline column — fold it into
            // the column instead of also storing a duplicate characteristic (one
            // fact, one home; the explicit purity_pct/mass_mg inputs below still
            // win). Only numeric values in the column's own unit qualify: a textual
            // "conforms" or a mass in another unit stays a characteristic row, so
            // nothing is silently lost or mis-scaled into the column.
            $fold = self::headline_fold($row);
            if ($fold === 'purity') {
                $purity = $row['value_num'];
                continue;
            }
            if ($fold === 'mass') {
                $mass = $row['value_num'];
                continue;
            }

            $chars[] = $row;
        }
        if (isset($in['purity_pct']) && $in['purity_pct'] !== '') {
            $purity = (float) $in['purity_pct'];
        }
        if (isset($in['mass_mg']) && $in['mass_mg'] !== '') {
            $mass = (float) $in['mass_mg'];
        }

        $product_id  = (int) ($in['product_id'] ?? 0);
        $product     = $product_id > 0 && function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $is_variable = $product instanceof \WC_Product && $product->is_type('variable');

        $columns = [
            'product_id'        => $product_id,
            'variation_id'      => isset($in['variation_id']) && $in['variation_id'] !== '' ? (int) $in['variation_id'] : null,
            'size_token'        => $size,
            'batch'             => sanitize_text_field((string) ($in['batch'] ?? '')),
            'batch_inferred'    => 0,
            'lab_slug'          => $lab_slug,
            'lab_label'         => sanitize_text_field($lab_label),
            'analysis_date'     => $iso,
            'analysis_date_raw' => $ok ? '' : sanitize_text_field((string) ($in['analysis_date'] ?? '')),
            'purity_pct'        => $purity,
            'mass_mg'           => $mass,
            'report_file_id'    => $resolved['file_id'],
            'report_url'        => esc_url_raw($resolved['url']),
            'verify_url'        => esc_url_raw((string) ($in['verify_url'] ?? '')),
            'report_kind'       => $resolved['kind'],
            'sort_order'        => (int) ($in['sort_order'] ?? 0),
            // A whole-product COA on a VARIABLE product applies to every size — flag it so
            // the "All sizes" label shows. Simple products (always size-less) keep "—".
            'applies_all_sizes' => (($size === '' && $is_variable) || !empty($in['applies_all_sizes'])) ? 1 : 0,
        ];

        return [$columns, $chars];
    }

    /**
     * Map ONE untrusted characteristic input ({name, value, unit}) to a sanitized
     * repository row — the single normalization shared by the admin/REST save path
     * and the bulk AI backfill, so they can't drift. Returns null for an empty row.
     *
     * @param array<string,mixed> $c
     * @return array{name_slug:string,name_label:string,value_num:?float,value_text:string,unit:string}|null
     */
    public static function char_row(array $c): ?array
    {
        $name = sanitize_text_field((string) ($c['name'] ?? $c['name_label'] ?? ''));
        $val  = (string) ($c['value'] ?? $c['value_num'] ?? '');
        if ($name === '' && $val === '') {
            return null;
        }
        $num = is_numeric($val) ? (float) $val : null;
        return [
            'name_slug'  => Normalize::name_slug($name),
            'name_label' => $name,
            'value_num'  => $num,
            'value_text' => $num === null ? sanitize_text_field($val) : '',
            'unit'       => Normalize::unit(sanitize_text_field((string) ($c['unit'] ?? ''))),
        ];
    }

    /**
     * Should this characteristic row fold into a headline column instead of being
     * stored? 'purity'|'mass' when it is numeric and in the column's own unit
     * (%/mg/none); null when it is a genuine characteristic.
     */
    public static function headline_fold(array $row): ?string
    {
        if ($row['value_num'] === null) {
            return null;
        }
        $unit = (string) $row['unit'];
        if ($row['name_slug'] === 'purity' && ($unit === '' || $unit === '%')) {
            return 'purity';
        }
        if ($row['name_slug'] === 'mass' && ($unit === '' || $unit === 'mg')) {
            return 'mass';
        }
        return null;
    }
}
