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
        // Drop thousands separators so "1,000mg" canonicalizes to 1000mg, not 1mg
        // (the [\d.]+ capture below otherwise stops at the comma).
        $s = preg_replace('/(?<=\d),(?=\d)/', '', $s) ?? $s;
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
     * The site's declared order for all-numeric dates: 'dmy', 'mdy', or '' to refuse
     * to guess. Set via COA → Settings (`coa_vault_date_order`) or the same-named
     * filter. Labs print 06/04/2026 meaning 6 April (EU) or June 4 (US) with nothing
     * on the certificate to tell them apart, so this is the only reliable signal.
     */
    public static function date_order(): string
    {
        $order = (string) apply_filters('coa_vault_date_order', (string) get_option('coa_vault_date_order', ''));
        return in_array($order, ['dmy', 'mdy'], true) ? $order : '';
    }

    /**
     * English month names and abbreviations as certificates print them, lower-case.
     */
    private const MONTHS = [
        'jan' => 1, 'january' => 1,
        'feb' => 2, 'february' => 2,
        'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4,
        'may' => 5,
        'jun' => 6, 'june' => 6,
        'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9,
        'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    /**
     * Parse a certificate date into ISO Y-m-d.
     *
     * Unambiguous inputs always parse: ISO (Y-m-d / Ymd, and year-first Y/m/d or
     * Y.m.d), a date with the month written as a word (8 Jan 2026, January 8th, 2026),
     * and any separated numeric date whose day component exceeds 12 (25/03/2026 can
     * only be d/m/Y, 03/25/2026 only m/d/Y). A genuinely ambiguous one like 06/04/2026
     * is REJECTED unless the site has declared its order — guessing silently mis-dates
     * the certificate and mis-orders "latest", so the raw text is preserved and flagged
     * instead.
     *
     * A two-digit year is accepted where the year comes LAST and read as 20YY — labs
     * such as Chromate print 06/30/25. It settles only the year: the day/month order of
     * 04/02/25 is still decided by the rules above.
     *
     * The AI scan passes in the date exactly as printed on the certificate, so the
     * same rules decide a scanned date and a typed one.
     *
     * @return array{0:?string,1:bool} [iso-date|null, parsed-ok]
     */
    public static function date(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [null, true]; // genuinely absent is not an anomaly
        }

        // Unambiguous machine formats.
        foreach (['Y-m-d', 'Ymd'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($dt instanceof \DateTimeImmutable && $dt->format($fmt) === $raw) {
                return [$dt->format('Y-m-d'), true];
            }
        }

        // Year-first numeric (2026/03/25, 2026.3.5): always year-month-day — labs
        // don't print year-day-month.
        if (preg_match('#^(\d{4})\s*[/.\-]\s*(\d{1,2})\s*[/.\-]\s*(\d{1,2})$#', $raw, $m) === 1) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // Month written as a word — unambiguous in any order.
        $named = self::month_name_date($raw);
        if ($named !== null) {
            return $named;
        }

        // Separated all-numeric date, year last — decide d/m/Y vs m/d/Y, or refuse.
        if (preg_match('#^(\d{1,2})\s*[/.\-]\s*(\d{1,2})\s*[/.\-]\s*(\d{4}|\d{2})$#', $raw, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $y = self::full_year($m[3]);

            if ($a > 12 && $b <= 12) {
                $day = $a;                      // only d/m/Y can be meant
                $month = $b;
            } elseif ($b > 12 && $a <= 12) {
                $month = $a;                    // only m/d/Y can be meant
                $day = $b;
            } elseif ($a <= 12 && $b <= 12) {
                $order = self::date_order();
                if ($order === 'dmy') {
                    $day = $a;
                    $month = $b;
                } elseif ($order === 'mdy') {
                    $month = $a;
                    $day = $b;
                } else {
                    return [null, false];       // ambiguous and undeclared — never guess
                }
            } else {
                return [null, false];           // both components > 12: not a date
            }

            return self::ymd($y, $month, $day);
        }

        return [null, false]; // unparseable → anomaly
    }

    /**
     * A date whose month is written as a word: 8 Jan 2026, 8th January, 2026,
     * 08-JAN-2026, 08-JAN-26, Jan 8, 2026, January 8th 2026, Sept. 8 2026, 2026-Jan-08.
     * A two-digit year only where the year comes last (as for numeric dates).
     *
     * @return array{0:?string,1:bool}|null null when $raw is not in a month-name shape
     */
    private static function month_name_date(string $raw): ?array
    {
        $s   = strtolower(preg_replace('/\s+/', ' ', $raw) ?? $raw);
        $ord = '(?:st|nd|rd|th)?';
        $patterns = [
            // Day first: 8 Jan 2026 · 8th January, 2026 · 08-JAN-2026 · 8.Jan.2026
            '#^(?<d>\d{1,2})' . $ord . '[\s.\-/]*(?<m>[a-z]{3,9})\.?[\s.,\-/]*(?<y>\d{4}|\d{2})$#',
            // Month first: Jan 8, 2026 · January 8th 2026 · JAN-08-2026
            '#^(?<m>[a-z]{3,9})\.?[\s.\-/]*(?<d>\d{1,2})' . $ord . '[\s.,\-/]+(?<y>\d{4}|\d{2})$#',
            // Year first: 2026-Jan-08 · 2026 January 8
            '#^(?<y>\d{4})[\s.\-/]*(?<m>[a-z]{3,9})\.?[\s.\-/]*(?<d>\d{1,2})$#',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $s, $m) !== 1) {
                continue;
            }
            $month = self::MONTHS[$m['m']] ?? null;
            if ($month === null) {
                return [null, false]; // a word, but not a month
            }
            return self::ymd(self::full_year($m['y']), $month, (int) $m['d']);
        }
        return null;
    }

    /** A year as printed: four digits as-is, two digits as 20YY (certificates are all this century). */
    private static function full_year(string $y): int
    {
        return strlen($y) === 2 ? 2000 + (int) $y : (int) $y;
    }

    /** @return array{0:?string,1:bool} */
    private static function ymd(int $y, int $month, int $day): array
    {
        if (!checkdate($month, $day, $y)) {
            return [null, false];
        }
        return [sprintf('%04d-%02d-%02d', $y, $month, $day), true];
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
        // Canonicalize a leading-dot decimal so ".5" and "0.5" produce the same token.
        if (str_starts_with($n, '.')) {
            $n = '0' . $n;
        }
        if (!str_contains($n, '.')) {
            return $n;
        }
        return rtrim(rtrim($n, '0'), '.');
    }
}
