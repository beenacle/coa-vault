<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\SizeAliasBuilder;
use CoaVault\Ingest\ClaudeClient;
use CoaVault\Support\Normalize;
use CoaVault\Support\Report;

/**
 * "Scan / Import COA": a parse-only AJAX endpoint. It sideloads the dropped
 * certificate into the Media Library, reads the QR verify link (decoded in the
 * browser and posted here) to infer the lab, optionally asks Claude to read the
 * numbers off the document, and returns a PRE-FILL for the existing add-COA form.
 *
 * It never writes a COA record — the admin reviews the pre-filled form and clicks
 * the normal "Save batch", so "review, not blind insert" holds by construction.
 */
final class ScanController
{
    private const MAX_BYTES = 15 * 1024 * 1024; // cap the payload sent to the API
    private const ALLOWED   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    public function register(): void
    {
        add_action('wp_ajax_coa_scan_report', [$this, 'scan']);
    }

    public function scan(): void
    {
        if (!check_ajax_referer(BatchController::NONCE, 'nonce', false) || !current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Not allowed.', 'coa-vault')], 403);
        }

        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0; // phpcs:ignore WordPress.Security
        if ($attachment_id > 0) {
            // Re-read a file already in the Media Library (picked via wp.media).
            if (get_post_type($attachment_id) !== 'attachment'
                || !in_array((string) get_post_mime_type($attachment_id), self::ALLOWED, true)) {
                wp_send_json_error(['message' => __('Pick an image or PDF from the Media Library.', 'coa-vault')], 400);
            }
        } else {
            // Freshly uploaded file: validate, then sideload into the Media Library.
            if (!current_user_can('upload_files')) {
                wp_send_json_error(['message' => __('You cannot upload files.', 'coa-vault')], 403);
            }
            $file = $_FILES['report'] ?? null; // phpcs:ignore WordPress.Security
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) <= 0) {
                wp_send_json_error(['message' => __('No file received.', 'coa-vault')], 400);
            }
            // Validate by real extension→mime before importing anything.
            $check = wp_check_filetype((string) $file['name']);
            if (!in_array((string) $check['type'], self::ALLOWED, true)) {
                wp_send_json_error(['message' => __('Upload a JPG, PNG, WEBP, GIF or PDF.', 'coa-vault')], 400);
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $uploaded = media_handle_upload('report', 0);
            if (is_wp_error($uploaded)) {
                wp_send_json_error(['message' => $uploaded->get_error_message()], 400);
            }
            $attachment_id = (int) $uploaded;
        }

        $mime  = (string) get_post_mime_type($attachment_id);
        $fields = $this->read_fields($attachment_id, $mime);

        // The verify link can come from a QR (decoded in the browser) or the AI read of
        // the printed text. A COA often carries MORE than one QR/link — e.g. a PubChem
        // compound link beside the lab's verification URL — so prefer a candidate that
        // resolves to a known testing lab and drop obvious non-verify links, rather than
        // blindly trusting whichever QR happened to decode first.
        $qr_url = isset($_POST['qr_url']) ? esc_url_raw((string) wp_unslash($_POST['qr_url'])) : ''; // phpcs:ignore WordPress.Security
        $verify = self::best_verify_url([$qr_url, esc_url_raw((string) ($fields['verify_url'] ?? ''))]);

        $lab_from_url = Normalize::lab_from_url($verify);
        $lab          = $lab_from_url['slug'] !== '' ? $lab_from_url : Normalize::lab((string) ($fields['lab'] ?? ''));

        [$iso, $ok] = Normalize::date((string) ($fields['analysis_date'] ?? ''));
        $report     = Report::resolve($attachment_id);
        $path       = get_attached_file($attachment_id);
        $thumb      = wp_get_attachment_image_url($attachment_id, 'thumbnail'); // false for a PDF with no generated preview

        // Average the per-sample measurements server-side (the model lists them
        // rather than averaging, which it does unreliably).
        $purity_pct = self::mean($fields['purity_samples'] ?? [], 3);
        $mass_mg    = self::mean($fields['mass_samples'] ?? [], 2);
        $peptide    = trim((string) ($fields['peptide'] ?? ''));

        // Build the extra-characteristic rows, dropping any that merely restate a
        // value already captured above (a replicate list, the content mass, or the
        // product identity) — those are the "unnecessary fields".
        $chars = [];
        foreach ((array) ($fields['characteristics'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $name  = sanitize_text_field((string) ($c['name'] ?? ''));
            $value = sanitize_text_field((string) ($c['value'] ?? ''));
            // Canonicalize the unit the same way the save path does (RecordInput), so a
            // re-read's "percent"/"Mg" compares equal to a saved "%"/"mg" and doesn't show
            // a phantom characteristics change (and the prefill matches what a save stores).
            $unit  = Normalize::unit(sanitize_text_field((string) ($c['unit'] ?? '')));
            if ($name === '' && $value === '') {
                continue;
            }
            // A value that is a list of numbers (e.g. "9.98, 9.86, 10.19") is a
            // replicate set that belongs in the averaged purity/mass, not here.
            if (preg_match('/[\d.]+\s*[,;]\s*[\d.]+/', $value)) {
                continue;
            }
            // A mg row equal to the peptide content already shown as Mass.
            if ($mass_mg !== null && stripos($unit, 'mg') !== false && is_numeric($value) && abs((float) $value - $mass_mg) < 0.01) {
                continue;
            }
            // An identity/name row that just repeats the product name.
            if ($peptide !== '' && strcasecmp(trim($value), $peptide) === 0) {
                continue;
            }
            // The FTIR identification narrative is a long restate-the-identity sentence.
            if (stripos($name, 'ftir') !== false) {
                continue;
            }
            // A certificate often labels the TOTAL sample mass (peptide + excipients)
            // simply "Mass". Left as-is it clashes with the main Mass mg field, which
            // holds the active content — two rows both called "Mass" with different
            // numbers. Relabel the survivor so the review form reads unambiguously.
            if (strcasecmp(trim($name), 'mass') === 0) {
                $name = 'Sample Mass';
            }
            $chars[] = ['name' => $name, 'value' => $value, 'unit' => $unit];
        }
        $chars = self::collapse_replicate_chars($chars);

        // Match the certificate's labelled size to one of THIS product's real
        // variations, so the "Applies to" dropdown pre-selects the right size.
        // Only set it when a variation genuinely matches, else leave whole-product.
        $product_id   = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0; // phpcs:ignore WordPress.Security
        $size_token   = Normalize::size_token((string) ($fields['size'] ?? ''));
        $variation_id = null;
        if ($product_id > 0 && $size_token !== '') {
            $variation_id = (new SizeAliasBuilder())->variation_for($product_id, $size_token);
            if ($variation_id === null) {
                $size_token = '';
            }
        }

        $prefill = [
            'id'             => '',
            'size_token'     => $size_token,
            'variation_id'   => $variation_id,
            'batch'          => sanitize_text_field((string) ($fields['batch'] ?? '')),
            'lab'            => ['label' => $lab['label']],
            'analysis_date'  => $ok ? $iso : '',
            'purity_pct'     => $purity_pct,
            'mass_mg'        => $mass_mg,
            'report'         => [
                'file_id'    => $report['file_id'],
                'url'        => $report['url'],
                'verify_url' => $verify,
                'filename'   => is_string($path) ? wp_basename($path) : '',
                'thumb_url'  => is_string($thumb) ? $thumb : '',
                'kind'       => $report['kind'],
                'filesize'   => (is_string($path) && file_exists($path)) ? size_format((int) filesize($path)) : '',
            ],
            'characteristics' => $chars,
        ];

        // A single file with more than one certificate (e.g. two size variants) is read
        // as just its FIRST certificate — warn rather than silently merge the rest.
        $cert_count = (int) ($fields['certificate_count'] ?? 1);
        $warning    = $cert_count > 1
            /* translators: %d: number of certificates found in the file */
            ? sprintf(
                _n(
                    'This file holds %d certificate — only the first was read. Save it, then add the other by dropping the file again and setting its size and batch.',
                    'This file holds %d certificates — only the first was read. Save it, then add the other(s) by dropping the file again and setting each one’s size and batch.',
                    $cert_count,
                    'coa-vault'
                ),
                $cert_count
            )
            : '';

        wp_send_json_success([
            'prefill' => $prefill,
            'ai_used' => $fields !== [],
            'peptide' => sanitize_text_field((string) ($fields['peptide'] ?? '')),
            'warning' => $warning,
        ]);
    }

    /**
     * Ask Claude to read the certificate, when a key is configured and the file is
     * small enough. Returns [] (manual fallback) on every other path.
     *
     * @return array<string,mixed>
     */
    private function read_fields(int $attachment_id, string $mime): array
    {
        if (!Settings::ai_enabled() || !in_array($mime, self::ALLOWED, true)) {
            return [];
        }
        $path = get_attached_file($attachment_id);
        if (!is_string($path) || !is_readable($path) || filesize($path) > self::MAX_BYTES) {
            return [];
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return [];
        }
        return (new ClaudeClient(Settings::anthropic_key()))->extract($bytes, $mime);
    }

    /**
     * Collapse replicate characteristic rows (same name + unit measured several
     * times, e.g. each blend component sampled in triplicate) into one averaged row.
     * Mixed/non-numeric groups are left untouched so nothing is lost.
     *
     * @param array<int,array{name:string,value:string,unit:string}> $chars
     * @return array<int,array{name:string,value:string,unit:string}>
     */
    private static function collapse_replicate_chars(array $chars): array
    {
        $groups = [];
        foreach ($chars as $c) {
            $groups[strtolower($c['name']) . '|' . strtolower($c['unit'])][] = $c;
        }
        $out = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $out[] = $group[0];
                continue;
            }
            $nums = [];
            foreach ($group as $g) {
                if (is_numeric($g['value'])) {
                    $nums[] = (float) $g['value'];
                }
            }
            if (count($nums) === count($group)) {
                $out[] = [
                    'name'  => $group[0]['name'],
                    'value' => (string) round(array_sum($nums) / count($nums), 2),
                    'unit'  => $group[0]['unit'],
                ];
            } else {
                foreach ($group as $g) {
                    $out[] = $g;
                }
            }
        }
        return array_values($out);
    }

    /**
     * Choose the best verification link from the candidates (a decoded QR and the
     * AI-read printed URL). Certificates often print extra links — most commonly a
     * PubChem / compound-reference URL — so prefer a candidate that resolves to a known
     * testing lab, drop clear non-verification links, and return '' when none qualifies.
     *
     * @param string[] $candidates
     */
    private static function best_verify_url(array $candidates): string
    {
        $clean = [];
        foreach ($candidates as $url) {
            $url = trim((string) $url);
            if ($url !== '' && !self::is_reference_url($url)) {
                $clean[] = $url;
            }
        }
        if ($clean === []) {
            return '';
        }
        foreach ($clean as $url) {
            if (Normalize::lab_from_url($url)['slug'] !== '') {
                return $url; // a known lab's domain — highest confidence
            }
        }
        return $clean[0];
    }

    /** A chemical/compound reference link (PubChem etc.) — never a COA verification URL. */
    private static function is_reference_url(string $url): bool
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true;
        }
        foreach (['pubchem.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov', 'wikipedia.org', 'chemspider.com', 'drugbank.com', 'drugbank.ca', 'sigmaaldrich.com', 'guidetopharmacology.org', 'commonchemistry.cas.org'] as $ref) {
            if ($host === $ref || str_ends_with($host, '.' . $ref)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mean of a list of measured samples, rounded — or null when the list is empty.
     *
     * @param mixed $samples
     */
    private static function mean($samples, int $precision): ?float
    {
        $nums = [];
        foreach ((array) $samples as $s) {
            if (is_numeric($s)) {
                $nums[] = (float) $s;
            }
        }
        if ($nums === []) {
            return null;
        }
        return round(array_sum($nums) / count($nums), $precision);
    }
}
