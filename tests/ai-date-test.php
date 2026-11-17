<?php
/**
 * Standalone check for the AI scan's date handling — no WordPress, no API key, no network.
 *
 *   php tests/ai-date-test.php
 *
 * Part 1 runs Normalize::date() directly. Part 2 runs the real "Scan / import" AJAX
 * handler (ScanController::scan) with a stubbed Anthropic response, so it covers the
 * request ClaudeClient builds, the field it reads back, and the prefill the editor,
 * "Re-read data" and the bulk "Read data with AI" list all consume. Exits non-zero on
 * any failure. tests/ is excluded from the release zip (.distignore).
 */

declare(strict_types=1);

namespace {
    define('ABSPATH', __DIR__ . '/');
    define('COA_VAULT_VERSION', 'test');
    define('COA_VAULT_ANTHROPIC_KEY', 'sk-ant-test-not-a-real-key');

    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'CoaVault\\')) {
            require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 9)) . '.php';
        }
    });

    // ── Minimal WordPress stubs ──────────────────────────────────────────────
    final class WP_Post {}
    final class TestJsonResponse extends \Exception
    {
        public function __construct(public bool $success, public mixed $data) { parent::__construct(); }
    }

    $GLOBALS['t_options'] = [];
    $GLOBALS['t_request'] = null;   // the body ClaudeClient POSTed
    $GLOBALS['t_ai']      = [];     // the JSON the stubbed model "returns"

    function get_option($name, $default = false) { return $GLOBALS['t_options'][$name] ?? $default; }
    function apply_filters($hook, $value, ...$args) { return $value; }
    function __($s, $d = null) { return $s; }
    function _n($single, $plural, $n, $d = null) { return $n === 1 ? $single : $plural; }
    function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
    function sanitize_title($s) { return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
    function esc_url_raw($u) { return (string) $u; }
    function wp_unslash($v) { return $v; }
    function wp_parse_url($u, $c = -1) { return parse_url((string) $u, $c); }
    function wp_json_encode($v) { return json_encode($v); }
    function wp_basename($p) { return basename((string) $p); }
    function size_format($n) { return $n . ' B'; }
    function check_ajax_referer(...$a) { return true; }
    function current_user_can($cap) { return true; }
    function get_post_type($id) { return 'attachment'; }
    function get_post_mime_type($id) { return 'image/png'; }
    function get_post($id) { return new WP_Post(); }
    function get_attached_file($id) { return $GLOBALS['t_file']; }
    function wp_get_attachment_url($id) { return 'https://store.test/wp-content/uploads/coa.png'; }
    function wp_get_attachment_image_url($id, $size) { return false; }
    function is_wp_error($x) { return false; }
    function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
    function wp_remote_retrieve_body($r) { return $r['body']; }
    function wp_remote_post($url, $args) {
        $GLOBALS['t_request'] = json_decode($args['body'], true);
        return ['response' => ['code' => 200], 'body' => json_encode([
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => json_encode($GLOBALS['t_ai'])]],
        ])];
    }
    function wp_send_json_success($data = null) { throw new TestJsonResponse(true, $data); }
    function wp_send_json_error($data = null, $code = null) { throw new TestJsonResponse(false, $data); }

    // ── Harness ──────────────────────────────────────────────────────────────
    $GLOBALS['t_fail'] = 0;
    function check(string $label, $got, $want): void
    {
        $ok = $got === $want;
        $GLOBALS['t_fail'] += $ok ? 0 : 1;
        $show = static fn ($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        printf("  %s  %-52s %s\n", $ok ? 'PASS' : 'FAIL', $label, $ok ? $show($got) : 'got ' . $show($got) . ', want ' . $show($want));
    }

    /** Run the real scan handler with the model "reading" $printed as the date. */
    function scan_with_date(?string $printed): array
    {
        $GLOBALS['t_ai'] = [
            'lab' => 'AccuMark Labs', 'batch' => 'TEST-1', 'purity_samples' => [99.1], 'mass_samples' => [10.2],
            'analysis_date_as_printed' => $printed, 'peptide' => 'Test peptide', 'size' => null,
            'verify_url' => null, 'certificate_count' => 1, 'characteristics' => [],
        ];
        $_POST = ['nonce' => 'x', 'attachment_id' => '42', 'product_id' => '0'];
        try {
            (new \CoaVault\Admin\ScanController())->scan();
        } catch (TestJsonResponse $r) {
            return ['success' => $r->success] + (array) $r->data;
        }
        throw new \RuntimeException('scan() sent no JSON response');
    }

    $GLOBALS['t_file'] = tempnam(sys_get_temp_dir(), 'coa');
    file_put_contents($GLOBALS['t_file'], "\x89PNG\r\n\x1a\nnot-really-an-image");

    // ── Part 1: Normalize::date() ────────────────────────────────────────────
    $date = static fn (string $s, string $order = ''): array => (function () use ($s, $order) {
        $GLOBALS['t_options']['coa_vault_date_order'] = $order;
        return \CoaVault\Support\Normalize::date($s);
    })();

    echo "Normalize::date(), date order unset:\n";
    check('"06/04/2026" ambiguous -> refused', $date('06/04/2026'), [null, false]);
    check('"03/25/2026" -> 2026-03-25', $date('03/25/2026'), ['2026-03-25', true]);
    check('"25/03/2026" -> 2026-03-25', $date('25/03/2026'), ['2026-03-25', true]);
    check('"8 Jan 2026" -> 2026-01-08', $date('8 Jan 2026'), ['2026-01-08', true]);
    check('"8th January, 2026"', $date('8th January, 2026'), ['2026-01-08', true]);
    check('"08-JAN-2026"', $date('08-JAN-2026'), ['2026-01-08', true]);
    check('"Jan 8, 2026"', $date('Jan 8, 2026'), ['2026-01-08', true]);
    check('"January 8th 2026"', $date('January 8th 2026'), ['2026-01-08', true]);
    check('"Sept. 8 2026"', $date('Sept. 8 2026'), ['2026-09-08', true]);
    check('"2026-Jan-08"', $date('2026-Jan-08'), ['2026-01-08', true]);
    check('"2026-03-25" ISO', $date('2026-03-25'), ['2026-03-25', true]);
    check('"20260325"', $date('20260325'), ['2026-03-25', true]);
    check('"2026/03/25" year first', $date('2026/03/25'), ['2026-03-25', true]);
    check('"2026.3.5" year first', $date('2026.3.5'), ['2026-03-05', true]);
    check('"" absent is not an anomaly', $date(''), [null, true]);
    check('"31 Feb 2026" impossible', $date('31 Feb 2026'), [null, false]);
    check('"Smarch 8 2026" not a month', $date('Smarch 8 2026'), [null, false]);
    check('"06/30/25" two-digit year, day > 12', $date('06/30/25'), ['2025-06-30', true]);
    check('"25.03.26" two-digit year, day > 12', $date('25.03.26'), ['2026-03-25', true]);
    check('"04/02/25" two-digit year, ambiguous', $date('04/02/25'), [null, false]);
    check('"8 Jan 26" / "08-JAN-26"', [$date('8 Jan 26'), $date('08-JAN-26')], [['2026-01-08', true], ['2026-01-08', true]]);
    check('"26/03/2025" stays d/m/Y (not y/m/d)', $date('26/03/2025'), ['2025-03-26', true]);
    check('"26-Jan-8" year-first needs 4 digits', $date('26-Jan-8'), [null, false]);
    check('"6/4/2026 9" junk', $date('6/4/2026 9'), [null, false]);
    check('"2026/25/03" year-day-month', $date('2026/25/03'), [null, false]);
    check('"Thu, 8 Jan 2026" weekday', $date('Thu, 8 Jan 2026'), [null, false]);
    echo "Normalize::date(), date order set:\n";
    check('mdy: "06/04/2026" -> 2026-06-04', $date('06/04/2026', 'mdy'), ['2026-06-04', true]);
    check('dmy: "06/04/2026" -> 2026-04-06', $date('06/04/2026', 'dmy'), ['2026-04-06', true]);
    check('mdy: "25/03/2026" still 2026-03-25', $date('25/03/2026', 'mdy'), ['2026-03-25', true]);
    check('dmy: "8 Jan 2026" unaffected', $date('8 Jan 2026', 'dmy'), ['2026-01-08', true]);
    check('mdy: "04/02/25" -> 2025-04-02', $date('04/02/25', 'mdy'), ['2025-04-02', true]);
    check('dmy: "04/02/25" -> 2025-02-04', $date('04/02/25', 'dmy'), ['2025-02-04', true]);

    // ── Part 2: the scan endpoint end to end ─────────────────────────────────
    echo "Scan endpoint (ScanController::scan with a stubbed AI read):\n";
    $GLOBALS['t_options']['coa_vault_date_order'] = '';
    $r = scan_with_date('06/04/2026');
    check('"06/04/2026": date left unset', $r['prefill']['analysis_date'], '');
    check('"06/04/2026": raw text kept', $r['prefill']['analysis_date_raw'], '06/04/2026');
    check('"06/04/2026": review notice names the date', str_contains($r['warning'], '“06/04/2026” could not be read unambiguously'), true);

    $req    = $GLOBALS['t_request'];
    $schema = $req['output_config']['format']['schema'];
    $prompt = $req['messages'][0]['content'][1]['text'];
    check('request schema asks for analysis_date_as_printed', isset($schema['properties']['analysis_date_as_printed']), true);
    check('request schema no longer has analysis_date', isset($schema['properties']['analysis_date']), false);
    check('date field is required', in_array('analysis_date_as_printed', $schema['required'], true), true);
    check('prompt no longer asks for YYYY-MM-DD', str_contains($prompt, 'YYYY-MM-DD'), false);
    check('prompt says copy EXACTLY as printed', str_contains($prompt, 'EXACTLY as it is printed'), true);
    check('prompt falls back to the published date', str_contains($prompt, 'published, reported or issued'), true);
    check('prompt rules out received/expiry dates', str_contains($prompt, 'Never use a received, sampled, manufacture or expiry date'), true);

    $r = scan_with_date('03/25/2026');
    check('"03/25/2026" -> 2026-03-25', $r['prefill']['analysis_date'], '2026-03-25');
    check('"03/25/2026": no raw text, no notice', [$r['prefill']['analysis_date_raw'], $r['warning']], ['', '']);

    $r = scan_with_date('8 Jan 2026');
    check('"8 Jan 2026" -> 2026-01-08', $r['prefill']['analysis_date'], '2026-01-08');
    check('"8 Jan 2026": no raw text, no notice', [$r['prefill']['analysis_date_raw'], $r['warning']], ['', '']);

    // Chromate certificates print month-first dates with a two-digit year.
    $r = scan_with_date('06/30/25');
    check('"06/30/25" (Chromate) -> 2025-06-30', [$r['prefill']['analysis_date'], $r['warning']], ['2025-06-30', '']);
    $r = scan_with_date('04/02/25');
    check('"04/02/25" (Chromate): blank, raw kept, notice', [$r['prefill']['analysis_date'], $r['prefill']['analysis_date_raw'], $r['warning'] !== ''], ['', '04/02/25', true]);

    $r = scan_with_date(null);
    check('no date printed: null date, no raw text, no notice', [$r['prefill']['analysis_date'], $r['prefill']['analysis_date_raw'], $r['warning']], [null, '', '']);

    $r = scan_with_date('Date of analysis: 06/04/2026, reviewed and approved by the laboratory QA manager');
    check('over-long text: raw capped to the 64-char column', mb_strlen($r['prefill']['analysis_date_raw']), 64);

    $GLOBALS['t_options']['coa_vault_date_order'] = 'mdy';
    $r = scan_with_date('06/04/2026');
    check('order mdy: "06/04/2026" -> 2026-06-04', $r['prefill']['analysis_date'], '2026-06-04');
    check('order mdy: no raw text, no notice', [$r['prefill']['analysis_date_raw'], $r['warning']], ['', '']);

    $r = scan_with_date('04/02/25');
    check('order mdy: "04/02/25" (Chromate) -> 2025-04-02', $r['prefill']['analysis_date'], '2025-04-02');

    $GLOBALS['t_options']['coa_vault_date_order'] = 'dmy';
    $r = scan_with_date('06/04/2026');
    check('order dmy: "06/04/2026" -> 2026-04-06', $r['prefill']['analysis_date'], '2026-04-06');

    unlink($GLOBALS['t_file']);
    echo $GLOBALS['t_fail'] === 0 ? "\nAll checks passed.\n" : "\n{$GLOBALS['t_fail']} check(s) FAILED.\n";
    exit($GLOBALS['t_fail'] === 0 ? 0 : 1);
}
