<?php

declare(strict_types=1);

namespace CoaVault\Ingest;

/**
 * Thin client over Anthropic's Messages API for reading a Certificate of Analysis.
 *
 * Uses the WordPress HTTP API (no Composer dependency, so the plugin stays
 * drop-in installable) to POST the certificate — an image as a vision block, a
 * PDF as a document block — and asks for a fixed JSON shape via structured
 * outputs. Every failure path returns [] so the scan flow degrades to manual
 * entry rather than erroring the metabox.
 */
final class ClaudeClient
{
    private const ENDPOINT      = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION   = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-haiku-4-5';

    // Anthropic limits: 10 MB per image and ~32 MB per request, measured on the
    // BASE64 payload (which is ~33% larger than the raw bytes). Guard against the
    // encoded size so an oversized scan fails cleanly to manual entry, never a
    // doomed API round-trip.
    private const MAX_IMAGE_B64 = 10 * 1024 * 1024;
    private const MAX_DOC_B64   = 30 * 1024 * 1024;

    public function __construct(private string $api_key)
    {
    }

    public function is_ready(): bool
    {
        return $this->api_key !== '';
    }

    /**
     * Read a certificate and return the extracted fields, or [] on any failure.
     *
     * @return array{lab?:?string,batch?:?string,purity_pct?:float|int|null,mass_mg?:float|int|null,analysis_date?:?string,peptide?:?string,verify_url?:?string}
     */
    public function extract(string $bytes, string $mime): array
    {
        if (!$this->is_ready() || $bytes === '') {
            return [];
        }

        $media = $this->media_block($mime, $bytes);
        if ($media === null) {
            return []; // unsupported type — caller falls back to manual entry
        }

        $body = [
            'model'      => self::model(),
            'max_tokens' => 1024,
            'messages'   => [[
                'role'    => 'user',
                'content' => [$media, ['type' => 'text', 'text' => self::PROMPT]],
            ]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
        ];

        $response = wp_remote_post(self::ENDPOINT, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => self::API_VERSION,
                'content-type'      => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || ($data['stop_reason'] ?? '') === 'refusal') {
            return [];
        }

        // Structured outputs guarantee the first text block is valid JSON.
        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text = (string) ($block['text'] ?? '');
                break;
            }
        }
        $fields = json_decode($text, true);
        return is_array($fields) ? $fields : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function media_block(string $mime, string $bytes): ?array
    {
        $b64 = base64_encode($bytes);
        $len = strlen($b64);
        if (str_starts_with($mime, 'image/')) {
            if ($len > self::MAX_IMAGE_B64) {
                return null; // over the API's per-image limit → caller falls back to manual
            }
            return ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];
        }
        if ($mime === 'application/pdf') {
            if ($len > self::MAX_DOC_B64) {
                return null;
            }
            return ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]];
        }
        return null;
    }

    /** Default model, overridable per-site (e.g. bump to claude-sonnet-4-6 for dense COAs). */
    private static function model(): string
    {
        if (defined('COA_VAULT_CLAUDE_MODEL') && (string) COA_VAULT_CLAUDE_MODEL !== '') {
            return (string) COA_VAULT_CLAUDE_MODEL;
        }
        return (string) apply_filters('coa_vault_claude_model', self::DEFAULT_MODEL);
    }

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $nullable = static fn (string $t): array => ['anyOf' => [['type' => $t], ['type' => 'null']]];
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'lab'           => $nullable('string'),
                'batch'         => $nullable('string'),
                // Raw per-sample measurements — the model lists them, the server
                // averages (LLMs are unreliable at arithmetic, and listing avoids
                // replicates leaking into characteristics).
                'purity_samples' => ['type' => 'array', 'items' => ['type' => 'number']],
                'mass_samples'   => ['type' => 'array', 'items' => ['type' => 'number']],
                'analysis_date' => $nullable('string'),
                'peptide'       => $nullable('string'),
                'size'          => $nullable('string'),
                'verify_url'    => $nullable('string'),
                // Extra measured figures — one entry per blend component's mass, or
                // any other distinct analyte. Empty for a plain single-analyte COA.
                'characteristics' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'name'  => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                            'unit'  => ['type' => 'string'],
                        ],
                        'required' => ['name', 'value', 'unit'],
                    ],
                ],
            ],
            'required' => ['lab', 'batch', 'purity_samples', 'mass_samples', 'analysis_date', 'peptide', 'size', 'verify_url', 'characteristics'],
        ];
    }

    private const PROMPT = <<<'TXT'
You are reading a laboratory Certificate of Analysis (COA) for a research peptide or chemical. Extract the fields below and return JSON. Use null for anything not clearly stated on the document — do not guess or infer.

Report only values that are actually MEASURED and printed in the results. Never derive a measurement from the product's declared / nominal label: the size in a sample or product name (e.g. the "10mg" in "Tirzepatide 10mg") is the label, NOT a measured result. A sterility-only or identity-only certificate has no measured mass or purity — leave those sample lists empty.

- lab: the testing laboratory's name exactly as printed — ANY lab, not only well-known ones (common ones include Janoshik, Chromate, AccuMark Labs, TrustPointe, BT Lab Testing, but use whatever lab the certificate actually names).
- batch: the batch / lot number / identifier. If there is no field literally labelled "Batch" or "Lot", use the client's batch identifier even when it is labelled differently (e.g. a "Client SID" like "POL-T10-17", or a manufacturer batch code) — but NOT the lab's own internal sample/task/job number.
- purity_samples: a list of EVERY measured purity %, one number per result printed (no % sign). A single purity is a one-element list (e.g. [99.02]); three replicate purities are [99.180, 99.147, 99.168]; a blend's total purity goes here too. Empty list [] if no purity is measured. Do NOT average or pick one — list each measured value exactly as printed; the system averages them.
- mass_samples: a list of EVERY measured net ACTIVE / peptide content in MILLIGRAMS for a SINGLE-compound product, one number per result printed (a single mass is [11.7]; three replicate masses are [9.98, 9.86, 10.19]). Use the peptide content, NOT the total sample or fill mass that includes excipients or diluent (e.g. for Peptide Content 37.4 mg / Excipients 47.3 mg / Sample Mass 84.7 mg, use [37.4] and put the excipient and sample-mass figures in characteristics). mass_samples MUST be an empty list [] when ANY of these is true: the certificate tests MORE THAN ONE active compound (a BLEND such as "BPC-157 + TB-500" has NO single main mass — put every component's mass in characteristics, never here); the content is in a NON-mg unit (mL, mcg/µg, g, IU/EU — put it in characteristics with its real unit); or no mass is measured. Do NOT average or pick one — list each value; the system averages them.
- analysis_date: the date of analysis in YYYY-MM-DD format.
- peptide: the product / compound name tested. For a blend, name all actives (e.g. "BPC-157 + TB-500").
- size: the product's labelled size / strength shown in the sample or product name (e.g. "10mg", "5mg", "10ml", "2mg") — the declared vial size that identifies which variation the certificate is for. This is the LABEL, not a measurement (it is fine that the same number is excluded from mass_mg). Use null if no size is shown.
- verify_url: the verification URL printed on the certificate, copied EXACTLY as shown, including the https:// scheme (add https:// if the document omits it). Do NOT invent, assemble, or guess a link: never build one by appending a sample/lot code or key to a domain (e.g. do not turn the domain "ACCUMARKLABS.COM" plus a code "UCSD-8SPY" into "accumarklabs.com/UCSD-8SPY"). If the document prints a full verification URL that already contains a key/code (e.g. "...?Key=253K78SPJVNU" or "...?accuverify_code=VXP3-4VZC"), return it exactly. If it only prints a bare verification page or domain (with any code shown separately, not as part of a URL), return just that page/domain as printed. If no verification URL is clearly printed, return null. A QR code alone is not a URL — do not transcribe one.
- characteristics: ONLY the rows of the certificate's RESULTS / tests table, EXCEPT the main compound's purity and mass (which you already returned as purity_samples / mass_samples). Each entry is {name, value, unit} and is one of: (a) a blend component's measured mass, e.g. {"name":"BPC-157","value":"11.65","unit":"mg"}; (b) a distinct measured property (potency assay, excipient content, pH, fill volume, a non-mg content figure); or (c) a qualitative test result (sterility, endotoxin, identity — value "Pass" / "Not Detected" / the text result, unit empty). A sterility-only or identity-only certificate's single test result MUST be captured here. When you include the TOTAL / sample mass (the figure that includes excipients or fillers, distinct from the main active content you returned in mass_samples), name that row "Sample Mass" — not just "Mass" — so it is not confused with the main active-content mass. STRICTLY EXCLUDE anything that is not a results-table row — do NOT add entries for the verification key or URL, sample/task/order/SID/job numbers, batch or lot, lab name, any date, client or manufacturer, the product/sample name, the declared size, or general appearance/description text from the header. Do NOT include the main compound's replicate masses or purities (those are in mass_samples / purity_samples) and do NOT repeat values already captured elsewhere: the overall/blend purity, the main content mass (e.g. a "Quantity" or "Content" mg row equal to the peptide content), the product identity/name (e.g. an "Identity = <product name>" row), or an FTIR identification / composition narrative (a long sentence like "FTIR sample spectrum confirms the presence of …" — skip it entirely). Use an empty array [] when the results table has no row other than the main purity and mass. For a non-numeric result put the text in value and leave unit empty.
TXT;
}
