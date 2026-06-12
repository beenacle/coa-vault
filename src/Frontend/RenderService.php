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
     * Catalog archive — every published product's COAs grouped under a linked
     * product heading. Powers `[coa_vault all="true"]`.
     */
    public function render_all_products(): string
    {
        $grouped = $this->records->all_for_published_products();
        if ($grouped === []) {
            return '<p class="coa-vault-empty">' . esc_html__('No certificates of analysis available.', 'coa-vault') . '</p>';
        }

        $out = '<div class="coa-vault-archive">';
        foreach ($grouped as $product_id => $records) {
            $title   = get_the_title($product_id);
            $link    = get_permalink($product_id);
            $heading = $link
                ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>'
                : esc_html($title);
            $out .= sprintf(
                '<section class="coa-vault-archive__item"><h3 class="coa-vault-archive__title">%s</h3>%s</section>',
                $heading,
                $this->render_records($records)
            );
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * @param array<string,mixed> $r
     */
    private function render_one(array $r): string
    {
        $latest = !empty($r['is_latest']);
        $title  = $r['batch'] !== '' ? $r['batch'] : __('Batch', 'coa-vault');

        // Disclosure label: batch, then a quiet lab · date line + status tags.
        $sub = [];
        if (!empty($r['lab']['label'])) {
            $sub[] = esc_html($r['lab']['label']);
        }
        if (!empty($r['analysis_date'])) {
            $sub[] = esc_html((string) $r['analysis_date']);
        }

        $summary = '<summary><span class="coa-vault-batch">' . esc_html($title) . '</span>';
        if ($sub !== []) {
            $summary .= ' <span class="coa-vault-sub">' . implode(' &middot; ', $sub) . '</span>';
        }
        if (!empty($r['applies_all_sizes'])) {
            $summary .= ' <span class="coa-vault-tag">' . esc_html__('All sizes', 'coa-vault') . '</span>';
        }
        if ($latest) {
            $summary .= ' <span class="coa-vault-tag">' . esc_html__('Latest', 'coa-vault') . '</span>';
        }
        $summary .= '</summary>';

        // Results as a native description list (key/value) — themes style <dl> already.
        $facts = [];
        if ($r['purity_pct'] !== null) {
            $facts[] = [__('Purity', 'coa-vault'), rtrim(rtrim((string) $r['purity_pct'], '0'), '.') . '%'];
        }
        if ($r['mass_mg'] !== null) {
            $facts[] = [__('Mass', 'coa-vault'), rtrim(rtrim((string) $r['mass_mg'], '0'), '.') . ' mg'];
        }
        foreach ((array) $r['characteristics'] as $c) {
            if (in_array($c['name'], ['purity', 'mass'], true)) {
                continue; // already shown via the hot columns
            }
            $val     = is_float($c['value']) ? rtrim(rtrim((string) $c['value'], '0'), '.') : (string) $c['value'];
            $facts[] = [($c['label'] ?: $c['name']), trim($val . ' ' . $c['unit'])];
        }

        $dl = '';
        if ($facts !== []) {
            $dl = '<dl class="coa-vault-facts">';
            foreach ($facts as [$key, $value]) {
                $dl .= '<dt>' . esc_html($key) . '</dt><dd>' . esc_html($value) . '</dd>';
            }
            $dl .= '</dl>';
        }

        $report = $this->render_report($r['report'], (string) ($r['lab']['label'] ?? ''));

        return '<li class="coa-vault-item"><details' . ($latest ? ' open' : '') . '>'
            . $summary . $dl . $report
            . '</details></li>';
    }

    /**
     * Render the certificate AND, when present, the original lab verification link.
     *
     * A local attachment is rendered the way WordPress itself does it —
     * wp_get_attachment_image() returns an <img> for images AND for PDFs (their
     * auto-generated first-page preview, with WP's own media icon as the fallback),
     * exactly like the Media Library / ACF. No PDF special-casing.
     *
     * @param array<string,mixed> $report
     */
    private function render_report(array $report, string $lab_label = ''): string
    {
        $file_id = isset($report['file_id']) ? (int) $report['file_id'] : 0;
        $url     = (string) ($report['url'] ?? '');
        $verify  = (string) ($report['verify_url'] ?? '');
        $alt     = esc_attr__('Certificate of Analysis', 'coa-vault');

        $parts = [];

        if ($file_id > 0) {
            // Native: image OR PDF preview, with srcset, handled by WordPress.
            $img  = wp_get_attachment_image($file_id, 'large', true, [
                'class'   => 'coa-vault-report-img',
                'alt'     => $alt,
                'loading' => 'lazy',
            ]);
            $href = wp_get_attachment_url($file_id) ?: $url;
            if ($img !== '' && $href !== '') {
                $parts[] = sprintf(
                    '<figure class="coa-vault-report"><a href="%s" target="_blank" rel="noopener">%s</a></figure>',
                    esc_url($href),
                    $img
                );
            }
        } elseif ($url !== '') {
            // External URL with no local attachment: inline images, link anything else.
            if (preg_match('#\.(png|jpe?g|gif|webp|avif|svg)(\?|\#|$)#i', $url)) {
                $parts[] = sprintf(
                    '<figure class="coa-vault-report"><a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="%2$s" loading="lazy"></a></figure>',
                    esc_url($url),
                    $alt
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
