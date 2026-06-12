<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;
use CoaVault\Data\Schema;

/**
 * COA coverage aids on the WooCommerce Products screen:
 *   - a "COAs" column showing how many live certificates each product has, and
 *   - a "No COA" view filter listing published products that have none.
 *
 * Read-only; it surfaces gaps where products are managed, so missing certificates
 * are easy to spot. Counts are primed for the whole page in one query (no N+1).
 */
final class ProductCoverage
{
    private const COLUMN = 'coa_vault';

    /** @var array<int,int>|null product_id => count, primed from the visible rows */
    private ?array $counts = null;

    public function __construct(private CoaRepository $records)
    {
    }

    public function register(): void
    {
        add_filter('manage_edit-product_columns', [$this, 'add_column']);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('the_posts', [$this, 'prime_counts'], 10, 2);
        add_filter('views_edit-product', [$this, 'add_view']);
        add_action('pre_get_posts', [$this, 'maybe_filter_missing']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function add_column(array $columns): array
    {
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $out[self::COLUMN] = __('COAs', 'coa-vault');
            }
            $out[$key] = $label;
        }
        if (!isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('COAs', 'coa-vault');
        }
        return $out;
    }

    public function render_column(string $column, int $post_id): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        if ($this->counts === null) {
            $this->counts = $this->records->coverage_counts([$post_id]);
        }
        $n = $this->counts[$post_id] ?? 0;

        if ($n > 0) {
            echo '<span style="font-weight:600;">' . (int) $n . '</span>';
            return;
        }
        printf(
            '<span style="color:#b32d2e;" title="%s">%s</span>',
            esc_attr__('No certificate of analysis', 'coa-vault'),
            esc_html__('None', 'coa-vault')
        );
    }

    /**
     * Prime every visible product's count in ONE query before the column renders.
     *
     * @param array<int,\WP_Post> $posts
     * @return array<int,\WP_Post>
     */
    public function prime_counts(array $posts, \WP_Query $query): array
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'product') {
            return $posts;
        }
        $ids          = array_map(static fn($p) => (int) $p->ID, $posts);
        $this->counts = $this->records->coverage_counts($ids);
        return $posts;
    }

    /**
     * @param array<string,string> $views
     * @return array<string,string>
     */
    public function add_view(array $views): array
    {
        $missing = $this->records->count_products_missing_coa();
        $current = isset($_GET['coa_missing']) ? ' class="current" aria-current="page"' : '';
        $url     = add_query_arg(
            ['post_type' => 'product', 'coa_missing' => 1],
            admin_url('edit.php')
        );

        $views['coa_missing'] = sprintf(
            '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
            esc_url($url),
            $current,
            esc_html__('No COA', 'coa-vault'),
            (int) $missing
        );
        return $views;
    }

    public function maybe_filter_missing(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== 'product' || empty($_GET['coa_missing'])) {
            return;
        }
        // Scope to published so the result set matches the "No COA (N)" count.
        $query->set('post_status', 'publish');
        add_filter('posts_where', [$this, 'where_missing']);
    }

    public function where_missing(string $where): string
    {
        global $wpdb;
        remove_filter('posts_where', [$this, 'where_missing']); // one-shot, this query only
        $t = Schema::records_table();
        return $where
            . " AND NOT EXISTS (SELECT 1 FROM {$t} r WHERE r.product_id = {$wpdb->posts}.ID AND r.source_present = 1)";
    }
}
