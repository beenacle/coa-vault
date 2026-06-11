<?php

declare(strict_types=1);

namespace CoaVault\Data;

/**
 * A single COA batch — the atomic unit users manage and the canonical shape the
 * migration produces. Carries its own characteristics (→ wp_coa_vault_characteristics).
 */
final class CoaRecord
{
    /** @var Characteristic[] */
    public array $characteristics = [];

    public function __construct(
        public int $product_id,
        public ?int $variation_id = null,
        public string $size_token = '',
        public string $batch = '',
        public bool $batch_inferred = false,
        public string $lab_slug = '',
        public string $lab_label = '',
        public ?string $analysis_date = null,
        public string $analysis_date_raw = '',
        public ?float $purity_pct = null,
        public ?float $mass_mg = null,
        public ?int $report_file_id = null,
        public string $report_url = '',
        public string $verify_url = '',
        public string $report_kind = 'none',
        public int $sort_order = 0,
        public bool $applies_all_sizes = false,
        public string $source_site = '',
        public string $source_type = '',
        public ?int $source_post_id = null,
        public ?int $source_row_index = null,
        public string $source_hash = '',
        public array $extra = [],
    ) {
    }

    /**
     * Column => value map for $wpdb, excluding id/timestamps/run_id which the
     * repository manages.
     *
     * @return array<string,mixed>
     */
    public function to_row(): array
    {
        return [
            'product_id'        => $this->product_id,
            'variation_id'      => $this->variation_id,
            'size_token'        => $this->size_token,
            'batch'             => $this->batch,
            'batch_inferred'    => $this->batch_inferred ? 1 : 0,
            'lab_slug'          => $this->lab_slug,
            'lab_label'         => $this->lab_label,
            'analysis_date'     => $this->analysis_date,
            'analysis_date_raw' => $this->analysis_date_raw,
            'purity_pct'        => $this->purity_pct,
            'mass_mg'           => $this->mass_mg,
            'report_file_id'    => $this->report_file_id,
            'report_url'        => $this->report_url,
            'verify_url'        => $this->verify_url,
            'report_kind'       => $this->report_kind,
            'sort_order'        => $this->sort_order,
            'applies_all_sizes' => $this->applies_all_sizes ? 1 : 0,
            'source_site'       => $this->source_site,
            'source_type'       => $this->source_type,
            'source_post_id'    => $this->source_post_id,
            'source_row_index'  => $this->source_row_index,
            'source_hash'       => $this->source_hash,
            'extra'             => $this->extra === [] ? null : wp_json_encode($this->extra),
        ];
    }
}
