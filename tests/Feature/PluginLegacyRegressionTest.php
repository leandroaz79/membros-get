<?php

namespace Tests\Feature;

use App\Plugins\PluginRegistry;
use Tests\TestCase;

class PluginLegacyRegressionTest extends TestCase
{
    public function test_settings_tabs_accept_legacy_plugin_component_paths(): void
    {
        $tabs = PluginRegistry::getSettingsTabs();
        foreach ($tabs as $tab) {
            if (($tab['ui_mode'] ?? '') === 'legacy') {
                $this->assertStringStartsWith('Plugin/', $tab['component']);
            }
        }
        $this->assertTrue(true);
    }

    public function test_integration_apps_legacy_component_paths(): void
    {
        $apps = PluginRegistry::getIntegrationApps();
        foreach ($apps as $app) {
            if (($app['ui_mode'] ?? 'legacy') === 'legacy' && ! empty($app['component'])) {
                $this->assertStringStartsWith('Plugin/', $app['component']);
            }
        }
        $this->assertTrue(true);
    }

    public function test_plugin_asset_route_serves_dist_when_present(): void
    {
        $path = base_path('plugins/getfy-plugin-starter/dist/ui.manifest.json');
        if (! is_file($path)) {
            $this->markTestSkipped('dist do starter ausente.');
        }
        $response = $this->get('/plugins/getfy-plugin-starter/assets/dist/ui.manifest.json');
        $response->assertOk();
    }
}
