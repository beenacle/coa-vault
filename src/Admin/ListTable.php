<?php

declare(strict_types=1);

namespace CoaVault\Admin;

use CoaVault\Data\CoaRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Catalog-wide COA list — the cross-product view no legacy site had. Sortable,
 * filterable by lab; only possible because batches are real rows.
 */
final class ListTable extends \WP_List_Table
{
    public function __construct(private CoaRepository $records)
    {
        parent::__construct([
            'singular' => 'coa',
            'plural'   => 'coas',
            'ajax'     => false,
        ]);
    }

    /** @return array<string,string> */
    public function get_columns(): array
    {
        return [
            'product' => __('Product', 'coa-vault'),
            'size'    => __('Size', 'coa-vault'),
            'batch'   => __('Batch', 'coa-vault'),
            'lab'     => __('Lab', 'coa-vault'),
            'date'    => __('Date', 'coa-vault'),
            'purity'  => __('Purity', 'coa-vault'),
            'report'  => __('Report', 'coa-vault'),
            'site'    => __('Source', 'coa-vault'),
        ];
    }

    public function prepare_items(): void
    {
        $per_page = 30;
        $page     = $this->get_pagenum();
        $lab      = isset($_REQUEST['lab']) ? sanitize_text_field((string) wp_unslash($_REQUEST['lab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $items = $this->records->query([
            'lab'      => $lab,
            'page'     => $page,
            'per_page' => $per_page,
        ]);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items           = $items;

        // Lightweight pagination (count via a follow-on page probe is overkill here).
        $this->set_pagination_args([
            'total_items' => count($items) < $per_page ? ($page - 1) * $per_page + count($items) : $page * $per_page + 1,
            'per_page'    => $per_page,
        ]);
    }

    /**
     * @param array<string,mixed> $item
     */
    public function column_default($item, $column_name): string
    {
        switch ($column_name) {
            case 'product':
                $title = get_the_title((int) $item['product_id']) ?: ('#' . $item['product_id']);
                return '<a href="' . esc_url(get_edit_post_link((int) $item['product_id'])) . '">' . esc_html($title) . '</a>';
            case 'size':
                return $item['size_token'] !== '' ? esc_html($item['size_token']) : ($item['applies_all_sizes'] ? esc_html__('All sizes', 'coa-vault') : '—');
            case 'batch':
                return esc_html($item['batch'] !== '' ? $item['batch'] : '—');
            case 'lab':
                return esc_html($item['lab']['label'] !== '' ? $item['lab']['label'] : '—');
            case 'date':
                return esc_html((string) ($item['analysis_date'] ?? '—'));
            case 'purity':
                return $item['purity_pct'] !== null ? esc_html((string) $item['purity_pct']) . '%' : '—';
            case 'report':
                return $item['report']['url'] !== ''
                    ? '<a href="' . esc_url($item['report']['url']) . '" target="_blank" rel="noopener">' . esc_html__('View', 'coa-vault') . '</a>'
                    : '—';
            case 'site':
                return esc_html((string) $item['source']['site']);
            default:
                return '';
        }
    }
}
