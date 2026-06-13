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
        ];
    }

    public function prepare_items(): void
    {
        $per_page = 30;
        $page     = $this->get_pagenum();
        $lab      = isset($_REQUEST['lab']) ? sanitize_text_field((string) wp_unslash($_REQUEST['lab'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        $filters = ['lab' => $lab];
        $total   = $this->records->count($filters, false);
        $items   = $this->records->query($filters + ['page' => $page, 'per_page' => $per_page], false);

        $this->_column_headers = [$this->get_columns(), [], []];
        $this->items           = $items;

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil(max(1, $total) / $per_page),
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
                $links = [];
                if ($item['report']['url'] !== '') {
                    $links[] = '<a href="' . esc_url($item['report']['url']) . '" target="_blank" rel="noopener">' . esc_html__('View', 'coa-vault') . '</a>';
                }
                if (!empty($item['report']['verify_url'])) {
                    $links[] = '<a href="' . esc_url((string) $item['report']['verify_url']) . '" target="_blank" rel="noopener">' . esc_html__('Verify', 'coa-vault') . '</a>';
                }
                return $links !== [] ? implode(' · ', $links) : '—';
            default:
                return '';
        }
    }
}
