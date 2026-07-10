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

    /** Initial server render for a product page (all the product's COAs; JS narrows per variation). */
    public function render_for_product(int $product_id): string
    {
        // Mark that a COA panel was placed this request, so the opt-out auto-injector
        // won't emit a duplicate when a shortcode/block already rendered one.
        do_action('coa_vault_rendered');
        self::enqueue_assets();

        // ALL of the product's records, not just product-level ones: a variable
        // product whose COAs are all size-bound would otherwise first paint (and be
        // indexed) as "no certificates available". Draft gating is the repository
        // default (public sees published only; editors may preview drafts).
        // aria-live: the JS swaps this wrap's content on variation change.
        $records = $this->records->find_by_product($product_id);
        $inner   = $this->render_records($records);
        return sprintf(
            '<div class="coa-vault-wrap" data-product-id="%d" aria-live="polite">%s</div>',
            $product_id,
            $inner
        );
    }

    /**
     * The product a shortcode/block placed without an explicit id should render:
     * the loop product, else the queried single product. Shared so the two can't
     * drift (and so the int-cast on get_the_ID()'s possible false lives in ONE place).
     */
    public function context_product_id(): int
    {
        global $product;
        if ($product instanceof \WC_Product) {
            return $product->get_id();
        }
        return is_singular('product') ? (int) get_the_ID() : 0;
    }

    /**
     * Enqueue the storefront style/script from inside the render, so a panel
     * placed somewhere placement-detection can't see (a page-builder widget, a
     * template part) still gets its assets. The handles are registered on
     * `wp_enqueue_scripts`; late style enqueues print in the footer (WP 5.5+).
     */
    private static function enqueue_assets(): void
    {
        wp_enqueue_style('coa-vault-frontend');
        wp_enqueue_script('coa-vault-frontend');
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

        // A native single-open accordion: the shared `name` makes the browser close
        // the other items when one opens — no JavaScript. All start collapsed so the
        // archive reads as a scannable index of every product.
        $out = '<div class="coa-vault-archive">';
        foreach ($grouped as $product_id => $records) {
            $title = get_the_title($product_id);
            $link  = get_permalink($product_id);
            $count = count($records);

            $summary = '<summary class="coa-vault-archive__summary">'
                . '<span class="coa-vault-archive__name">' . esc_html($title) . '</span>'
                . '<span class="coa-vault-archive__meta">'
                /* translators: %s: number of COA batches for this product */
                . esc_html(sprintf(_n('%s batch', '%s batches', $count, 'coa-vault'), number_format_i18n($count)))
                . '</span></summary>';

            $body = '';
            if ($link) {
                $body .= '<a class="coa-vault-archive__link" href="' . esc_url($link) . '">'
                    . esc_html__('View product', 'coa-vault') . ' &#8599;</a>';
            }
            $body .= $this->render_records($records);

            $out .= '<details class="coa-vault-archive__item" name="coa-vault-archive">'
                . $summary . $body . '</details>';
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
            $facts[] = [__('Purity', 'coa-vault'), self::num($r['purity_pct']) . '%'];
        }
        if ($r['mass_mg'] !== null) {
            $facts[] = [__('Mass', 'coa-vault'), self::num($r['mass_mg']) . ' mg'];
        }
        foreach ((array) $r['characteristics'] as $c) {
            if (in_array($c['name'], ['purity', 'mass'], true)) {
                continue; // already shown via the hot columns
            }
            if ($c['value'] === null) {
                continue; // no value to show — would render a dangling unit
            }
            $val     = is_float($c['value']) ? self::num($c['value']) : (string) $c['value'];
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

    /** Format a measured number: trim trailing fractional zeros without mangling whole numbers (10.0 stays "10", not "1"). */
    private static function num($value): string
    {
        $s = (string) $value;
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
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

        $parts    = [];
        $rendered = false;

        if ($file_id > 0) {
            // Native: image OR PDF page-1 preview, with srcset, handled by WordPress.
            $img  = wp_get_attachment_image($file_id, 'large', true, [
                'class'   => 'coa-vault-report-img',
                'alt'     => $alt,
                'loading' => 'lazy',
            ]);
            $href = wp_get_attachment_url($file_id) ?: $url;
            if ($img !== '' && $href !== '') {
                $rendered = true;
                $parts[]  = sprintf(
                    '<figure class="coa-vault-report"><a href="%s" target="_blank" rel="noopener">%s</a></figure>',
                    esc_url($href),
                    $img
                );
                // A PDF preview only shows page 1; link the full file so the rest
                // (e.g. page 2's endotoxin/sterility results) is one click away.
                if (str_starts_with((string) get_post_mime_type($file_id), 'application/pdf')) {
                    $parts[] = sprintf(
                        '<a class="coa-vault-report-pdf" href="%s" target="_blank" rel="noopener">%s &#8599;</a>',
                        esc_url($href),
                        esc_html__('View full report (PDF)', 'coa-vault')
                    );
                }
            }
            // A deleted/missing attachment falls through to the URL branch below,
            // so a record migrated with both file_id and url still shows something.
        }

        if (!$rendered && $url !== '') {
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
            // The lab's own verification link, shown so a shopper can check the source.
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
