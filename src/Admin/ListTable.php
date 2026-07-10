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
            'cb'      => '<input type="checkbox" />',
            'product' => __('Product', 'coa-vault'),
            'size'    => __('Size', 'coa-vault'),
            'batch'   => __('Batch', 'coa-vault'),
            'lab'     => __('Lab', 'coa-vault'),
            'date'    => __('Date', 'coa-vault'),
            'purity'  => __('Purity', 'coa-vault'),
            'report'  => __('Report', 'coa-vault'),
        ];
    }

    /**
     * The one bulk action: re-read the selected records' attached certificates with
     * AI and review the proposed values before anything is saved. Handled entirely
     * in JS (coa-list.js) — a plain form submit with this action is a no-op.
     *
     * @return array<string,string>
     */
    public function get_bulk_actions(): array
    {
        return ['coa_read_data' => __('Read data with AI', 'coa-vault')];
    }

    /**
     * Row checkbox. Carries the record's current values as JSON so the bulk-read
     * flow can (a) skip records with nothing to fill and (b) propose ONLY values
     * for fields that are currently empty — backfill, never overwrite.
     *
     * @param array<string,mixed> $item
     */
    public function column_cb($item): string
    {
        $file_id = $item['report']['file_id'] ?? null;
        if (!$file_id) {
            return ''; // nothing attached — nothing the AI could read
        }
        $title = get_the_title((int) $item['product_id']) ?: ('#' . $item['product_id']);
        $size  = (string) $item['size_token'];
        $data  = [
            'id'         => (int) $item['id'],
            'product_id' => (int) $item['product_id'],
            'title'      => $size !== '' ? $title . ' ' . $size : $title,
            'file_id'    => (int) $file_id,
            'cur'        => [
                'batch'  => (string) $item['batch'],
                'lab'    => (string) $item['lab']['label'],
                'date'   => (string) ($item['analysis_date'] ?? ''),
                'purity' => $item['purity_pct'],
                'mass'   => $item['mass_mg'],
                'verify' => (string) ($item['report']['verify_url'] ?? ''),
                'chars'  => count($item['characteristics']),
            ],
        ];
        return sprintf(
            '<input type="checkbox" class="coa-bf-cb" name="coa_ids[]" value="%d" data-coa="%s" />',
            (int) $item['id'],
            esc_attr((string) wp_json_encode($data))
        );
    }

    /**
     * Anything the AI read could still fill: an attached file plus at least one
     * empty core field (or no extra characteristics yet).
     *
     * @param array<string,mixed> $item
     */
    private static function is_backfillable(array $item): bool
    {
        if (empty($item['report']['file_id'])) {
            return false;
        }
        return $item['batch'] === ''
            || $item['lab']['label'] === ''
            || $item['analysis_date'] === null
            || $item['purity_pct'] === null
            || $item['mass_mg'] === null
            || (string) ($item['report']['verify_url'] ?? '') === ''
            || $item['characteristics'] === [];
    }

    /**
     * Columns the user can sort by. Each maps to a whitelisted orderby key handled in
     * CoaRepository::query(); the value's bool is the initial sort direction.
     *
     * @return array<string,array{0:string,1:bool}>
     */
    public function get_sortable_columns(): array
    {
        return [
            'product' => ['product', false],
            'lab'     => ['lab', false],
            'date'    => ['date', true],
            'purity'  => ['purity', false],
        ];
    }

    public function prepare_items(): void
    {
        // The bulk action is JS-driven; a plain form submit (JS failed to load)
        // must say so rather than silently reloading the page.
        if ($this->current_action() === 'coa_read_data') {
            printf(
                '<div class="notice notice-warning inline"><p>%s</p></div>',
                esc_html__('“Read data with AI” needs JavaScript — it did not run. Reload the page and try again.', 'coa-vault')
            );
        }

        $per_page = 30;
        $page     = $this->get_pagenum();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list sort/filter via GET.
        $lab      = isset($_REQUEST['lab']) ? sanitize_text_field((string) wp_unslash($_REQUEST['lab'])) : '';
        $orderby  = isset($_REQUEST['orderby']) ? sanitize_key((string) wp_unslash($_REQUEST['orderby'])) : '';
        $order    = isset($_REQUEST['order']) ? sanitize_key((string) wp_unslash($_REQUEST['order'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $filters = ['lab' => $lab, 'orderby' => $orderby, 'order' => $order];
        $total   = $this->records->count($filters, false);
        $items   = $this->records->query($filters + ['page' => $page, 'per_page' => $per_page], false);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
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
                if (self::is_backfillable($item)) {
                    // Single-record entry into the same read→review→apply flow as the
                    // bulk action; the anchor targets the panel so a no-JS click at
                    // least lands somewhere meaningful instead of jumping to page top.
                    $links[] = '<a href="#coa-backfill-root" class="coa-read-row">' . esc_html__('Read data', 'coa-vault') . '</a>';
                }
                return $links !== [] ? implode(' · ', $links) : '—';
            default:
                return '';
        }
    }
}
