<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * CRUD + queries for COA records and their characteristics. All table access for
 * COA data goes through here — nothing else reaches around to $wpdb.
 *
 * Note: `is_latest` is intentionally NOT stored (it would be a write-amplification
 * and concurrency hazard); newest-per-size is derived at read time.
 */
final class CoaRepository
{
    /**
     * Idempotent upsert keyed on source_hash. Re-running a migration updates the
     * existing row in place and fully replaces its characteristics.
     *
     * @return int The COA record id.
     */
    public function upsert(CoaRecord $record, ?int $migration_run_id = null): int
    {
        global $wpdb;
        $records = Schema::records_table();
        $chars   = Schema::characteristics_table();
        $now     = current_time('mysql', true);

        $row = $record->to_row();
        $row['updated_at']       = $now;
        $row['source_present']   = 1;
        $row['migration_run_id'] = $migration_run_id;

        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$records} WHERE source_hash = %s", $record->source_hash)
        );

        if ($existing_id > 0) {
            $wpdb->update($records, $row, ['id' => $existing_id]);
            $coa_id = $existing_id;
            $wpdb->delete($chars, ['coa_id' => $coa_id]);
        } else {
            $row['created_at'] = $now;
            $wpdb->insert($records, $row);
            $coa_id = (int) $wpdb->insert_id;
        }

        $position = 0;
        foreach ($record->characteristics as $c) {
            $wpdb->insert($chars, [
                'coa_id'     => $coa_id,
                'name_slug'  => $c->name_slug,
                'name_label' => $c->name_label,
                'value_num'  => $c->value_num,
                'value_text' => $c->value_text,
                'unit'       => $c->unit,
                'position'   => $c->position !== 0 ? $c->position : $position,
            ]);
            $position++;
        }

        return $coa_id;
    }

    /** Does a record with this idempotency hash already exist? (drives insert-vs-update reporting). */
    public function exists(string $source_hash): bool
    {
        global $wpdb;
        $records = Schema::records_table();
        return (bool) $wpdb->get_var(
            $wpdb->prepare("SELECT 1 FROM {$records} WHERE source_hash = %s LIMIT 1", $source_hash)
        );
    }

    /**
     * Tombstone records from a given source that were NOT touched by the latest run,
     * so deletions at the source are detectable without destroying data.
     */
    public function tombstone_missing(string $source_site, int $migration_run_id): int
    {
        global $wpdb;
        $records = Schema::records_table();
        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$records} SET source_present = 0
                 WHERE source_site = %s AND (migration_run_id <> %d OR migration_run_id IS NULL)",
                $source_site,
                $migration_run_id
            )
        );
    }

    /** Count live records for a site (diagnostics). */
    public function count_for_site(string $source_site): int
    {
        global $wpdb;
        $records = Schema::records_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$records} WHERE source_site = %s AND source_present = 1",
                $source_site
            )
        );
    }

    /**
     * Distinct labs actually used across all COAs (slug => label), for self-extending
     * autocomplete + report filters — so a custom lab typed once appears thereafter.
     *
     * @return array<string,string>
     */
    public function distinct_labs(): array
    {
        global $wpdb;
        $t    = Schema::records_table();
        $rows = $wpdb->get_results("SELECT DISTINCT lab_slug, lab_label FROM {$t} WHERE lab_slug <> '' ORDER BY lab_label ASC");
        $out  = [];
        foreach ($rows as $r) {
            $out[(string) $r->lab_slug] = (string) $r->lab_label;
        }
        return $out;
    }

    // Reads — return records in the canonical shaped form used by REST + render.

    /** @return array<int,array<string,mixed>> size asc, then newest-first. */
    public function find_by_product(int $product_id, bool $only_present = true, bool $published_only = true): array
    {
        // Reads default to published-only so the public REST endpoints can't leak
        // COAs for draft/pending products; admin/editor callers pass false.
        if ($published_only && get_post_status($product_id) !== 'publish') {
            return [];
        }
        global $wpdb;
        $t   = Schema::records_table();
        $sql = "SELECT * FROM {$t} WHERE product_id = %d";
        if ($only_present) {
            $sql .= ' AND source_present = 1';
        }
        $sql .= ' ORDER BY size_token ASC, analysis_date DESC, id DESC';
        return $this->hydrate_many($wpdb->get_results($wpdb->prepare($sql, $product_id)));
    }

    /**
     * The matching contract: variation_id → size_token → product-level ('').
     *
     * @return array<int,array<string,mixed>>
     */
    public function resolve(int $product_id, ?int $variation_id, ?string $size_token, bool $latest_only = false, bool $published_only = true): array
    {
        if ($published_only && get_post_status($product_id) !== 'publish') {
            return [];
        }
        global $wpdb;
        $t    = Schema::records_table();
        $rows = [];

        if ($variation_id) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE product_id = %d AND variation_id = %d AND source_present = 1 ORDER BY analysis_date DESC, id DESC",
                $product_id,
                $variation_id
            ));
        }
        if (!$rows && $size_token !== null && $size_token !== '') {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE product_id = %d AND size_token = %s AND source_present = 1 ORDER BY analysis_date DESC, id DESC",
                $product_id,
                $size_token
            ));
        }
        if (!$rows) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$t} WHERE product_id = %d AND size_token = '' AND source_present = 1 ORDER BY analysis_date DESC, id DESC",
                $product_id
            ));
        }

        $hydrated = $this->hydrate_many($rows);
        return $latest_only && $hydrated ? [$hydrated[0]] : $hydrated;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id, bool $published_only = true): ?array
    {
        global $wpdb;
        $t   = Schema::records_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $id));
        if (!$row) {
            return null;
        }
        if ($published_only && get_post_status((int) $row->product_id) !== 'publish') {
            return null;
        }
        $h = $this->hydrate_many([$row]);
        return $h[0] ?? null;
    }

    /**
     * Catalog-wide reporting query.
     *
     * @param array<string,mixed> $f lab, site, purity_max, product_id, page, per_page
     * @return array<int,array<string,mixed>>
     */
    public function query(array $f, bool $published_only = true): array
    {
        global $wpdb;
        $t                = Schema::records_table();
        [$where, $params] = $this->build_where($f, $published_only);

        $per      = max(1, min(200, (int) ($f['per_page'] ?? 50)));
        $page     = max(1, (int) ($f['page'] ?? 1));
        $params[] = $per;
        $params[] = ($page - 1) * $per;

        $sql = "SELECT * FROM {$t} WHERE " . implode(' AND ', $where)
             . ' ORDER BY analysis_date DESC, id DESC LIMIT %d OFFSET %d';

        return $this->hydrate_many($wpdb->get_results($wpdb->prepare($sql, ...$params)));
    }

    /**
     * Total rows matching the same filters as query() (no LIMIT) — drives correct
     * pagination so the total can never diverge from the paginated result.
     *
     * @param array<string,mixed> $f
     */
    public function count(array $f, bool $published_only = true): int
    {
        global $wpdb;
        $t                = Schema::records_table();
        [$where, $params] = $this->build_where($f, $published_only);
        $sql              = "SELECT COUNT(*) FROM {$t} WHERE " . implode(' AND ', $where);

        return (int) ($params === []
            ? $wpdb->get_var($sql)
            : $wpdb->get_var($wpdb->prepare($sql, ...$params)));
    }

    /**
     * Shared WHERE clause + bind params for the catalog query and its count.
     *
     * @param array<string,mixed> $f
     * @param bool $published_only Restrict to COAs whose product is published.
     * @return array{0:string[],1:array<int,mixed>}
     */
    private function build_where(array $f, bool $published_only = true): array
    {
        $where  = ['source_present = 1'];
        $params = [];

        if (!empty($f['lab'])) {
            $where[]  = 'lab_slug = %s';
            $params[] = (string) $f['lab'];
        }
        if (!empty($f['site'])) {
            $where[]  = 'source_site = %s';
            $params[] = (string) $f['site'];
        }
        if (isset($f['purity_max']) && $f['purity_max'] !== '') {
            $where[]  = 'purity_pct <= %f';
            $params[] = (float) $f['purity_max'];
        }
        if (!empty($f['product_id'])) {
            $where[]  = 'product_id = %d';
            $params[] = (int) $f['product_id'];
        }
        if ($published_only) {
            // Literal subquery (no user input) — keeps catalog reads off draft products.
            global $wpdb;
            $where[] = "product_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish')";
        }

        return [$where, $params];
    }

    /**
     * Count of LIVE COA records per product, for the given product ids — one query,
     * so the Products-list coverage column has no N+1.
     *
     * @param int[] $product_ids
     * @return array<int,int> product_id => record count
     */
    public function coverage_counts(array $product_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
        if ($ids === []) {
            return [];
        }
        global $wpdb;
        $t            = Schema::records_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows         = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT product_id, COUNT(*) AS n FROM {$t}
                 WHERE source_present = 1 AND product_id IN ({$placeholders})
                 GROUP BY product_id",
                ...$ids
            )
        );

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r->product_id] = (int) $r->n;
        }
        return $out;
    }

    /** Number of PUBLISHED products that have no live COA record (coverage gap). */
    public function count_products_missing_coa(): int
    {
        global $wpdb;
        $t = Schema::records_table();
        // No user input — table names are from Schema, the rest is literal.
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             AND NOT EXISTS (SELECT 1 FROM {$t} r WHERE r.product_id = p.ID AND r.source_present = 1)"
        );
    }

    /**
     * All LIVE COA records for PUBLISHED products, grouped by product and ordered
     * by product title — powers the catalog/archive shortcode. Two queries total
     * (records + their characteristics via hydrate_many); no per-product N+1.
     *
     * @return array<int,array<int,array<string,mixed>>> product_id => shaped records
     */
    public function all_for_published_products(): array
    {
        global $wpdb;
        $t    = Schema::records_table();
        // No user input — table names are from Schema, the rest is literal.
        $rows = $wpdb->get_results(
            "SELECT r.* FROM {$t} r
             JOIN {$wpdb->posts} p ON p.ID = r.product_id
             WHERE r.source_present = 1 AND p.post_type = 'product' AND p.post_status = 'publish'
             ORDER BY p.post_title ASC, r.size_token ASC, r.analysis_date DESC, r.id DESC"
        );

        $grouped = [];
        foreach ($this->hydrate_many($rows) as $rec) {
            $grouped[(int) $rec['product_id']][] = $rec;
        }
        return $grouped;
    }

    // Admin writes (manual records — not migration). Keyed by id, not source_hash.

    /**
     * @param array<string,mixed> $columns
     * @param array<int,array<string,mixed>> $characteristics
     */
    public function save_from_admin(?int $id, array $columns, array $characteristics): int
    {
        global $wpdb;
        $records = Schema::records_table();
        $chars   = Schema::characteristics_table();
        $now     = current_time('mysql', true);

        $columns['updated_at']     = $now;
        $columns['source_present'] = 1;

        if ($id) {
            $wpdb->update($records, $columns, ['id' => $id]);
            $wpdb->delete($chars, ['coa_id' => $id]);
            $coa_id = $id;
        } else {
            $columns['source_site'] = 'admin';
            $columns['source_type'] = 'manual';
            $columns['source_hash'] = sha1('manual:' . uniqid('', true));
            $columns['created_at']  = $now;
            $wpdb->insert($records, $columns);
            $coa_id = (int) $wpdb->insert_id;
        }

        $position = 0;
        foreach ($characteristics as $c) {
            $wpdb->insert($chars, [
                'coa_id'     => $coa_id,
                'name_slug'  => (string) ($c['name_slug'] ?? 'unknown'),
                'name_label' => (string) ($c['name_label'] ?? ''),
                'value_num'  => $c['value_num'] ?? null,
                'value_text' => (string) ($c['value_text'] ?? ''),
                'unit'       => (string) ($c['unit'] ?? ''),
                'position'   => $position++,
            ]);
        }

        return $coa_id;
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::characteristics_table(), ['coa_id' => $id]);
        $wpdb->delete(Schema::records_table(), ['id' => $id]);
    }

    // Hydration

    /**
     * @param array<int,object> $rows
     * @return array<int,array<string,mixed>>
     */
    private function hydrate_many(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        global $wpdb;
        $ct  = Schema::characteristics_table();
        $ids = array_map(static fn ($r): int => (int) $r->id, $rows);
        $in  = implode(',', array_fill(0, count($ids), '%d'));

        $chars = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$ct} WHERE coa_id IN ({$in}) ORDER BY position ASC, id ASC", ...$ids)
        );
        $by_coa = [];
        foreach ($chars as $c) {
            $by_coa[(int) $c->coa_id][] = $c;
        }

        // Derive is_latest per (product_id, size_token): newest analysis_date, tie-break id.
        $best = [];
        foreach ($rows as $r) {
            $key = $r->product_id . '|' . $r->size_token;
            $d   = $r->analysis_date ?? '0000-00-00';
            if (!isset($best[$key]) || [$d, (int) $r->id] > [$best[$key]['d'], $best[$key]['id']]) {
                $best[$key] = ['d' => $d, 'id' => (int) $r->id];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $key       = $r->product_id . '|' . $r->size_token;
            $is_latest = $best[$key]['id'] === (int) $r->id;
            $out[]     = $this->shape($r, $by_coa[(int) $r->id] ?? [], $is_latest);
        }
        return $out;
    }

    /**
     * @param array<int,object> $chars
     * @return array<string,mixed>
     */
    private function shape(object $r, array $chars, bool $is_latest): array
    {
        $characteristics = array_map(static function (object $c): array {
            $value = $c->value_num !== null
                ? (float) $c->value_num
                : ($c->value_text !== '' ? $c->value_text : null);
            return [
                'name'     => $c->name_slug,
                'label'    => $c->name_label,
                'value'    => $value,
                'unit'     => $c->unit,
                'position' => (int) $c->position,
            ];
        }, $chars);

        return [
            'id'                => (int) $r->id,
            'product_id'        => (int) $r->product_id,
            'variation_id'      => $r->variation_id !== null ? (int) $r->variation_id : null,
            'size_token'        => $r->size_token,
            'batch'             => $r->batch,
            'batch_inferred'    => (bool) $r->batch_inferred,
            'lab'               => ['slug' => $r->lab_slug, 'label' => $r->lab_label],
            'analysis_date'     => $r->analysis_date,
            'purity_pct'        => $r->purity_pct !== null ? (float) $r->purity_pct : null,
            'mass_mg'           => $r->mass_mg !== null ? (float) $r->mass_mg : null,
            'report'            => [
                'kind'       => $r->report_kind,
                'file_id'    => $r->report_file_id !== null ? (int) $r->report_file_id : null,
                'url'        => $r->report_url,
                'verify_url' => $r->verify_url ?? '',
            ],
            'is_latest'         => $is_latest,
            'sort_order'        => (int) $r->sort_order,
            'applies_all_sizes' => (bool) $r->applies_all_sizes,
            'characteristics'   => $characteristics,
            'source'            => [
                'site'      => $r->source_site,
                'type'      => $r->source_type,
                'post_id'   => $r->source_post_id !== null ? (int) $r->source_post_id : null,
                'row_index' => $r->source_row_index !== null ? (int) $r->source_row_index : null,
            ],
        ];
    }
}
