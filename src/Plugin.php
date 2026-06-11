<?php

declare(strict_types=1);

namespace CoaVault;

use CoaVault\Admin\AdminMenu;
use CoaVault\Admin\AdminRenderer;
use CoaVault\Admin\Assets as AdminAssets;
use CoaVault\Admin\BatchController;
use CoaVault\Admin\ProductPanel;
use CoaVault\Data\CoaRepository;
use CoaVault\Data\Installer;
use CoaVault\Data\SizeAliasBuilder;
use CoaVault\Data\SizeAliasRepository;
use CoaVault\Frontend\Assets as FrontendAssets;
use CoaVault\Frontend\AutoInject;
use CoaVault\Frontend\Block;
use CoaVault\Frontend\RenderService;
use CoaVault\Frontend\Shortcode;
use CoaVault\Frontend\VariationInjector;

/**
 * Lightweight container / bootstrapper. Wires the data layer, admin, frontend and
 * REST. Migration lives in the separate "COA Vault — Migration" companion plugin,
 * which reads this container's repositories via records()/aliases().
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private ?CoaRepository $records = null;
    private ?SizeAliasRepository $aliases = null;
    private ?RenderService $renderer = null;

    public static function instance(): Plugin
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        // Keep the schema current on plugin updates without requiring re-activation.
        Installer::maybe_upgrade();

        // COA is WooCommerce product data — bail if WooCommerce isn't active.
        if (!class_exists('WooCommerce')) {
            return;
        }

        $this->register_rest();
        $this->register_frontend();

        if (is_admin()) {
            $this->register_admin();
        }
    }

    private function register_rest(): void
    {
        (new \CoaVault\Rest\RestServiceProvider($this->records(), $this->renderer()))->register();
    }

    private function register_frontend(): void
    {
        $renderer = $this->renderer();
        (new VariationInjector())->register();
        (new Shortcode($renderer))->register();
        (new Block($renderer))->register();
        (new AutoInject($renderer))->register();
        (new FrontendAssets())->register();
    }

    private function register_admin(): void
    {
        $admin_renderer = new AdminRenderer($this->records(), new SizeAliasBuilder());
        (new ProductPanel($admin_renderer))->register();
        (new BatchController($this->records(), $admin_renderer))->register();
        (new AdminMenu($this->records()))->register();
        (new AdminAssets())->register();
    }

    public function records(): CoaRepository
    {
        return $this->records ??= new CoaRepository();
    }

    public function aliases(): SizeAliasRepository
    {
        return $this->aliases ??= new SizeAliasRepository();
    }

    public function renderer(): RenderService
    {
        return $this->renderer ??= new RenderService($this->records());
    }
}
