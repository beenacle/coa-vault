<?php

declare(strict_types=1);

namespace CoaVault\Rest;

use CoaVault\Data\CoaRepository;
use CoaVault\Data\RecordInput;

/**
 * Catalog-wide reporting reads + cap-gated CRUD for headless admin.
 */
final class CoaController
{
    public function __construct(private CoaRepository $records)
    {
    }

    public function register_routes(): void
    {
        register_rest_route('coa-vault/v1', '/coas', [
            [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => [$this, 'index'],
                // 'type' alone documents but does not enforce — the callbacks do.
                'args'                => [
                    'lab'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => 'rest_validate_request_arg'],
                    'site'       => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => 'rest_validate_request_arg'],
                    'purity_max' => ['type' => 'number', 'validate_callback' => 'rest_validate_request_arg'],
                    'product_id' => ['type' => 'integer', 'validate_callback' => 'rest_validate_request_arg'],
                    'page'       => ['type' => 'integer', 'minimum' => 1, 'validate_callback' => 'rest_validate_request_arg'],
                    'per_page'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'validate_callback' => 'rest_validate_request_arg'],
                ],
            ],
            [
                'methods'             => 'POST',
                'permission_callback' => [$this, 'can_edit'],
                'callback'            => [$this, 'create'],
            ],
        ]);

        register_rest_route('coa-vault/v1', '/coas/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => [$this, 'show'],
            ],
            [
                'methods'             => 'PUT, PATCH',
                'permission_callback' => [$this, 'can_edit'],
                'callback'            => [$this, 'update'],
            ],
            [
                'methods'             => 'DELETE',
                'permission_callback' => [$this, 'can_edit'],
                'callback'            => [$this, 'destroy'],
            ],
        ]);
    }

    public function can_edit(): bool
    {
        return current_user_can('edit_products');
    }

    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $published_only = !current_user_can('edit_products');
        $filters        = [
            'lab'        => $request->get_param('lab'),
            'site'       => $request->get_param('site'),
            'purity_max' => $request->get_param('purity_max'),
            'product_id' => $request->get_param('product_id'),
        ];
        $per_page = max(1, min(200, (int) ($request->get_param('per_page') ?? 50)));

        $records = $this->records->query(
            $filters + ['page' => $request->get_param('page'), 'per_page' => $per_page],
            $published_only
        );

        // Standard collection pagination headers, same as core collections.
        $total    = $this->records->count($filters, $published_only);
        $response = new \WP_REST_Response(RecordSchema::public_list($records), 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) (int) ceil($total / $per_page));
        return $response;
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $record = $this->records->find((int) $request['id'], !current_user_can('edit_products'));
        if ($record === null) {
            return new \WP_REST_Response(['message' => 'Not found'], 404);
        }
        return new \WP_REST_Response(RecordSchema::public_shape($record), 200);
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        [$columns, $chars] = RecordInput::to_columns((array) $request->get_json_params());
        if ($columns['product_id'] <= 0 || get_post_type($columns['product_id']) !== 'product') {
            return new \WP_REST_Response(['message' => 'product_id must reference an existing product'], 400);
        }
        $id     = $this->records->save_from_admin(null, $columns, $chars);
        $record = $this->records->find($id, false);
        return new \WP_REST_Response($record !== null ? RecordSchema::public_shape($record) : null, 201);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id       = (int) $request['id'];
        $existing = $this->records->find($id, false);
        if ($existing === null) {
            return new \WP_REST_Response(['message' => 'Not found'], 404);
        }
        // Merge the body over the CURRENT record: to_columns() emits every column
        // (absent input becomes ''/null), so mapping the raw body alone would turn
        // a partial PATCH into a silent full-blank of every omitted field.
        $in = array_merge(self::shaped_to_input($existing), (array) $request->get_json_params());

        [$columns, $chars] = RecordInput::to_columns($in);
        unset($columns['product_id']); // immutable on update
        $this->records->save_from_admin($id, $columns, $chars);
        $record = $this->records->find($id, false);
        return new \WP_REST_Response($record !== null ? RecordSchema::public_shape($record) : null, 200);
    }

    /**
     * Back-map a shaped record (CoaRepository::find()) to the flat input form
     * RecordInput::to_columns() consumes, as the base a partial update merges onto.
     * lab_slug is deliberately omitted: to_columns re-derives it from lab_label, and
     * a stale slug would override a client's new label.
     *
     * @param array<string,mixed> $rec
     * @return array<string,mixed>
     */
    private static function shaped_to_input(array $rec): array
    {
        return [
            'product_id'        => $rec['product_id'],
            'variation_id'      => $rec['variation_id'] ?? '',
            'size_token'        => $rec['size_token'],
            'batch'             => $rec['batch'],
            'lab_label'         => $rec['lab']['label'] ?? '',
            'analysis_date'     => $rec['analysis_date'] ?? '',
            'purity_pct'        => $rec['purity_pct'] ?? '',
            'mass_mg'           => $rec['mass_mg'] ?? '',
            'report_file_id'    => $rec['report']['file_id'] ?? '',
            'report_url'        => $rec['report']['url'] ?? '',
            'verify_url'        => $rec['report']['verify_url'] ?? '',
            'report_kind'       => $rec['report']['kind'] ?? '',
            'sort_order'        => $rec['sort_order'] ?? 0,
            'applies_all_sizes' => !empty($rec['applies_all_sizes']) ? 1 : '',
            'characteristics'   => array_map(
                static fn (array $c): array => [
                    'name'  => ($c['label'] ?? '') !== '' ? $c['label'] : $c['name'],
                    'value' => $c['value'],
                    'unit'  => $c['unit'],
                ],
                (array) ($rec['characteristics'] ?? [])
            ),
        ];
    }

    public function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->records->delete((int) $request['id']);
        return new \WP_REST_Response(null, 204);
    }
}
