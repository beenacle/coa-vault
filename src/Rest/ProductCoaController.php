<?php

declare(strict_types=1);

namespace CoaVault\Rest;

use CoaVault\Data\CoaRepository;
use CoaVault\Frontend\RenderService;

/**
 * Per-product read endpoints, including the variation→COA matching contract used
 * by both the storefront JS and any headless consumer (identical logic).
 */
final class ProductCoaController
{
    public function __construct(
        private CoaRepository $records,
        private RenderService $renderer,
    ) {
    }

    public function register_routes(): void
    {
        register_rest_route('coa-vault/v1', '/products/(?P<id>\d+)/coas', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'list_for_product'],
            'args'                => [
                'size'   => ['type' => 'string', 'required' => false],
                'latest' => ['type' => 'boolean', 'required' => false],
            ],
        ]);

        register_rest_route('coa-vault/v1', '/products/(?P<id>\d+)/resolve', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'resolve'],
            'args'                => [
                'variation_id' => ['type' => 'integer', 'required' => false],
                'size'         => ['type' => 'string', 'required' => false],
                'latest'       => ['type' => 'boolean', 'required' => false],
            ],
        ]);
    }

    public function list_for_product(\WP_REST_Request $request): \WP_REST_Response
    {
        $product_id = (int) $request['id'];
        $size       = $request->get_param('size');

        if ($size !== null && $size !== '') {
            $records = $this->records->resolve($product_id, null, (string) $size, (bool) $request->get_param('latest'));
        } else {
            $records = $this->records->find_by_product($product_id);
        }

        return new \WP_REST_Response(RecordSchema::public_list($records), 200);
    }

    public function resolve(\WP_REST_Request $request): \WP_REST_Response
    {
        $product_id   = (int) $request['id'];
        $variation_id = $request->get_param('variation_id') ? (int) $request->get_param('variation_id') : null;
        $size         = $request->get_param('size') !== null ? (string) $request->get_param('size') : null;

        $records = $this->records->resolve($product_id, $variation_id, $size, (bool) $request->get_param('latest'));

        return new \WP_REST_Response([
            'records' => RecordSchema::public_list($records),
            'html'    => $this->renderer->render_records($records),
        ], 200);
    }
}
