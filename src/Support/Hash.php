<?php

declare(strict_types=1);

namespace CoaVault\Support;

/**
 * Deterministic idempotency key for a COA record.
 *
 * The component set is chosen per the migration design so re-runs UPDATE in place
 * instead of duplicating, and so batch-less sites don't collide or duplicate:
 *   - batch-bearing sites: row_index + batch + size_token are stable across re-runs.
 *   - triumphant (no batch): caller passes variation_id as the stable key and OMITS
 *     the mutable report URL (updating a COA must not mint a new row).
 *   - titan (no batch): caller passes row_index + analysis_date, NOT the report.
 */
final class Hash
{
    /**
     * @param array<int,string|int|null> $parts Ordered, stable components.
     */
    public static function source(array $parts): string
    {
        $normalized = array_map(
            static fn ($p): string => (string) ($p ?? ''),
            $parts
        );
        return sha1(implode('|', $normalized));
    }
}
