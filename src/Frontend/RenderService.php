<?php

declare(strict_types=1);

namespace CoaVault\Frontend;

use CoaVault\Data\CoaRepository;

/**
 * The SINGLE source of truth for COA markup. The block, the shortcode, the
 * auto-inject hook, and the REST /resolve endpoint all render through here, so
 * on-site and headless output can never diverge.
 */
final class RenderService
{
    public function __construct(private CoaRepository $records)
    {
    }

    /** Initial server render for a product page (product-level COAs; JS swaps per variation). */
    public function render_for_product(int $product_id): string
    {
        $records = $this->records->resolve($product_id, null, null);
        $inner   = $this->render_records($records);
        return sprintf(
            '<div class="coa-vault-wrap" data-product-id="%d">%s</div>',
            $product_id,
            $inner
        );
    }

    /**
     * Render a set of already-shaped records, newest-first.
     *
     * @param array<int,array<string,mixed>> $records
     */
    public function render_records(array $records): string
    {
        if ($records === []) {
            return '<p class="coa-vault-empty">' . esc_html__('No certificates of analysis available.', 'coa-vault') . '</p>';
        }

        $out = '<ul class="coa-vault-list">';
        foreach ($records as $r) {
            $out .= $this->render_one($r);
        }
        $out .= '</ul>';
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     */
    private function render_one(array $r): string
    {
        $latest    = !empty($r['is_latest']);
        $title     = $r['batch'] !== '' ? $r['batch'] : __('Batch', 'coa-vault');
        $meta      = [];

        if (!empty($r['lab']['label'])) {
            $meta[] = esc_html($r['lab']['label']);
        }
        if (!empty($r['analysis_date'])) {
            $meta[] = esc_html((string) $r['analysis_date']);
        }
        if ($r['purity_pct'] !== null) {
            $meta[] = esc_html(sprintf('%s%% purity', rtrim(rtrim((string) $r['purity_pct'], '0'), '.')));
        }
        if ($r['mass_mg'] !== null) {
            $meta[] = esc_html(sprintf('%smg', rtrim(rtrim((string) $r['mass_mg'], '0'), '.')));
        }

        $extra_chars = [];
        foreach ((array) $r['characteristics'] as $c) {
            if (in_array($c['name'], ['purity', 'mass'], true)) {
                continue; // already shown via the hot columns
            }
            $val = is_float($c['value']) ? rtrim(rtrim((string) $c['value'], '0'), '.') : (string) $c['value'];
            $extra_chars[] = esc_html(trim(($c['label'] ?: $c['name']) . ' ' . $val . ' ' . $c['unit']));
        }

        $report = $this->render_report($r['report'], (string) ($r['lab']['label'] ?? ''));
        $note   = !empty($r['applies_all_sizes'])
            ? '<span class="coa-vault-note">' . esc_html__('Applies to all sizes', 'coa-vault') . '</span>'
            : '';

        $classes = 'coa-vault-item' . ($latest ? ' is-latest' : '');
        $open    = $latest ? ' open' : '';

        return sprintf(
            '<li class="%s"><details%s><summary><strong>%s</strong> %s %s</summary>%s%s%s</details></li>',
            esc_attr($classes),
            $open,
            esc_html($title),
            $note,
            $meta !== [] ? '<span class="coa-vault-meta">' . implode(' · ', $meta) . '</span>' : '',
            $extra_chars !== [] ? '<ul class="coa-vault-chars"><li>' . implode('</li><li>', $extra_chars) . '</li></ul>' : '',
            $report,
            ''
        );
    }

    /**
     * Render the certificate file (image/link) AND, when present, the original
     * lab verification link — the two are distinct artifacts and both are shown.
     *
     * @param array<string,mixed> $report
     */
    private function render_report(array $report, string $lab_label = ''): string
    {
        $url    = (string) ($report['url'] ?? '');
        $kind   = (string) ($report['kind'] ?? 'none');
        $verify = (string) ($report['verify_url'] ?? '');

        $parts = [];

        if ($url !== '' && $kind !== 'none') {
            if ($kind === 'image') {
                $parts[] = sprintf(
                    '<a class="coa-vault-report" href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="%2$s" loading="lazy"></a>',
                    esc_url($url),
                    esc_attr__('Certificate of Analysis', 'coa-vault')
                );
            } else {
                $parts[] = sprintf(
                    '<a class="coa-vault-report coa-vault-report--link" href="%s" target="_blank" rel="noopener">%s</a>',
                    esc_url($url),
                    esc_html__('View report', 'coa-vault')
                );
            }
        }

        if ($verify !== '') {
            $text = $lab_label !== ''
                /* translators: %s: lab name, e.g. Janoshik */
                ? sprintf(__('Verify on %s', 'coa-vault'), $lab_label)
                : __('Verify authenticity', 'coa-vault');
            $parts[] = sprintf(
                '<a class="coa-vault-verify" href="%s" target="_blank" rel="noopener nofollow">%s &#8599;</a>',
                esc_url($verify),
                esc_html($text)
            );
        }

        return implode('', $parts);
    }
}
