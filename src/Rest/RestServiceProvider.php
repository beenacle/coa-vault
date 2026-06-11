<?php

declare(strict_types=1);

namespace CoaVault\Rest;

use CoaVault\Data\CoaRepository;
use CoaVault\Frontend\RenderService;

/**
 * Registers all coa-vault/v1 REST routes.
 */
final class RestServiceProvider
{
    public function __construct(
        private CoaRepository $records,
        private RenderService $renderer,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        (new ProductCoaController($this->records, $this->renderer))->register_routes();
        (new CoaController($this->records))->register_routes();
    }
}
