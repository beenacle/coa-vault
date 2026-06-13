<?php

declare(strict_types=1);

namespace CoaVault\Support;

/**
 * All value normalization in one place, so the token a COA is STORED under and
 * the token derived from a live variation at RUNTIME are produced by identical
 * code and can never drift. This is the single most safety-critical class:
 * a drift here means a customer sees the wrong certificate for their vial.
 */
final class Normalize
{
    /**
     * Canonicalize a size/weight token: "5 MG" | "5mg" | "5 mg" | "10-mg" | 5 → "5mg".
     * Returns '' when there is no parseable size (→ COA applies to the whole product).
     */
    public static function size_token(string|int|float|null $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $s = strtolower(trim((string) $raw));
        if ($s === '') {
            return '';
        }
        // Pure number → assume milligrams (the dominant unit across all sites).
        if (is_numeric($s)) {
            return self::trim_decimal($s) . 'mg';
        }
        if (preg_match('/([\d.]+)\s*-?\s*(mcg|mg|kg|g|iu|ml|kit)?/u', $s, $m) === 1) {
            // The unit group is optional: when it doesn't match (a number followed by an
            // unrecognized unit, e.g. "30 caps") $m[2] is unset, so default to mg here —
            // testing $m[2] !== '' would both warn and skip the intended default.
            $unit = !empty($m[2]) ? $m[2] : 'mg';
            return self::trim_decimal($m[1]) . $unit;
        }
        // Non-numeric size label (rare) — collapse whitespace, keep as-is.
        return preg_replace('/\s+/', '', $s) ?? $s;
    }

    /**
     * Map raw lab text to a controlled-vocab slug + display label.
     *
     * @return array{slug:string,label:string,known:bool}
     */
    public static function lab(string $raw): array
    {
        $label = trim($raw);
        if ($label === '') {
            return ['slug' => '', 'label' => '', 'known' => true];
        }
        $key = strtolower(preg_replace('/\s+/', ' ', $label) ?? $label);
        if (isset(Vocab::LAB_ALIASES[$key])) {
            $slug = Vocab::LAB_ALIASES[$key];
            return ['slug' => $slug, 'label' => Vocab::LABS[$slug], 'known' => true];
        }
        // Unknown lab — derive a stable slug so it is still filterable/reportable,
        // keep the raw label, and flag it as not (yet) in the controlled vocabulary.
        return ['slug' => self::lab_slug($label), 'label' => $label, 'known' => false];
    }

    /** Derive a stable slug from a free-text lab label (same convention as name_slug). */
    public static function lab_slug(string $label): string
    {
        $slug = sanitize_title($label);
        return $slug !== '' ? str_replace('-', '_', $slug) : '';
    }

    /**
     * Infer the lab from a verify/report URL's host (e.g. a Janoshik or TrustPointe
     * link) for sites that carry no explicit lab field. Matches the host exactly or
     * as a sub-domain of a known host.
     *
     * @return array{slug:string,label:string,known:bool}
     */
    public static function lab_from_url(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['slug' => '', 'label' => '', 'known' => true];
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '') {
            foreach (Vocab::LAB_HOSTS as $domain => $slug) {
                if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                    return ['slug' => $slug, 'label' => Vocab::LABS[$slug] ?? $slug, 'known' => true];
                }
            }
        }
        return ['slug' => '', 'label' => '', 'known' => false];
    }

    /**
     * Map a raw characteristic/measurement label to a canonical name slug.
     */
    public static function name_slug(string $raw): string
    {
        $key = strtolower(trim($raw));
        if (isset(Vocab::MEASURE_ALIASES[$key])) {
            return Vocab::MEASURE_ALIASES[$key];
        }
        $slug = sanitize_title($raw);
        return $slug !== '' ? str_replace('-', '_', $slug) : 'unknown';
    }

    /** Canonicalize a unit to one of the allowed values, else keep verbatim. */
    public static function unit(string $raw): string
    {
        $u = trim($raw);
        if ($u === '%' || strtolower($u) === 'percent') {
            return '%';
        }
        if (strtolower($u) === 'mg') {
            return 'mg';
        }
        return $u; // unmappable unit kept verbatim (e.g. "EU/mg")
    }

    /**
     * Parse a legacy date in any of the three formats seen across sites into ISO Y-m-d.
     * Must round-trip or it is rejected (NULL + raw kept + flagged by the caller).
     *
     * @return array{0:?string,1:bool} [iso-date|null, parsed-ok]
     */
    public static function date(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, true]; // genuinely absent is not an anomaly
        }
        foreach (['Y-m-d', 'Ymd', 'd/m/Y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($dt instanceof \DateTimeImmutable && $dt->format($fmt) === $raw) {
                return [$dt->format('Y-m-d'), true];
            }
        }
        return [null, false]; // unparseable → anomaly
    }

    /** Parse a numeric string into a float, or null when non-numeric. */
    public static function decimal(string $raw): ?float
    {
        $raw = trim($raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }

    private static function trim_decimal(string $n): string
    {
        if (!str_contains($n, '.')) {
            return $n;
        }
        $n = rtrim($n, '0');
        return rtrim($n, '.');
    }
}
