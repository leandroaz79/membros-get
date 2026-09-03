<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Plugins\PluginPublicRouteRegistrar;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginPublicRouteTest extends TestCase
{
    use RefreshDatabase;

    private function registerPluginRoutes(string $slug): void
    {
        $path = base_path('plugins/'.$slug);
        if (! is_dir($path)) {
            $this->markTestSkipped("Plugin {$slug} ausente.");
        }
        $manifest = PluginRegistry::readManifest($path);
        $pluginRow = array_merge($manifest ?? [], [
            'slug' => $slug,
            'path' => $path,
        ]);
        PluginPublicRouteRegistrar::register($pluginRow);
        $viewsPath = $path.DIRECTORY_SEPARATOR.'views';
        if (is_dir($viewsPath)) {
            app('view')->addNamespace('plugin.getfy_vitrine_demo', $viewsPath);
        }
        Plugin::firstOrCreate(
            ['slug' => $slug],
            ['name' => $slug, 'version' => '1.0.0', 'is_enabled' => true]
        );
    }

    public function test_vitrine_demo_catalog_is_public(): void
    {
        $this->registerPluginRoutes('getfy-vitrine-demo');

        $response = $this->get('/p/vitrine-demo/');
        $response->assertOk();
        $response->assertSee('Vitrine Demo', false);
    }

    public function test_mercado_envios_webhook_accepts_post(): void
    {
        $this->registerPluginRoutes('mercado-envios-stub');

        $response = $this->postJson('/p/mercado-envios-stub/webhook', ['test' => true]);
        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }
}
