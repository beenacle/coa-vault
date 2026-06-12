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
                'args'                => [
                    'lab'        => ['type' => 'string'],
                    'site'       => ['type' => 'string'],
                    'purity_max' => ['type' => 'number'],
                    'product_id' => ['type' => 'integer'],
                    'page'       => ['type' => 'integer'],
                    'per_page'   => ['type' => 'integer'],
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
        $records = $this->records->query([
            'lab'        => $request->get_param('lab'),
            'site'       => $request->get_param('site'),
            'purity_max' => $request->get_param('purity_max'),
            'product_id' => $request->get_param('product_id'),
            'page'       => $request->get_param('page'),
            'per_page'   => $request->get_param('per_page'),
        ]);
        return new \WP_REST_Response(RecordSchema::public_list($records), 200);
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $record = $this->records->find((int) $request['id']);
        if ($record === null) {
            return new \WP_REST_Response(['message' => 'Not found'], 404);
        }
        return new \WP_REST_Response(RecordSchema::public_shape($record), 200);
    }

    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        [$columns, $chars] = RecordInput::to_columns((array) $request->get_json_params());
        if ($columns['product_id'] <= 0) {
            return new \WP_REST_Response(['message' => 'product_id is required'], 400);
        }
        $id     = $this->records->save_from_admin(null, $columns, $chars);
        $record = $this->records->find($id);
        return new \WP_REST_Response($record !== null ? RecordSchema::public_shape($record) : null, 201);
    }

    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request['id'];
        if ($this->records->find($id) === null) {
            return new \WP_REST_Response(['message' => 'Not found'], 404);
        }
        [$columns, $chars] = RecordInput::to_columns((array) $request->get_json_params());
        unset($columns['product_id']); // immutable on update
        $this->records->save_from_admin($id, $columns, $chars);
        $record = $this->records->find($id);
        return new \WP_REST_Response($record !== null ? RecordSchema::public_shape($record) : null, 200);
    }

    public function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $this->records->delete((int) $request['id']);
        return new \WP_REST_Response(null, 204);
    }
}
