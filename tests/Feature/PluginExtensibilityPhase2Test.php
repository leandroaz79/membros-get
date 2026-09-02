<?php

namespace Tests\Feature;

use App\Plugins\PluginAssetQueue;
use App\Plugins\PluginCapabilityRegistry;
use App\Plugins\PluginExtensionRegistry;
use App\Plugins\PluginMiddlewareRegistry;
use App\Plugins\PluginRegistry;
use Illuminate\Http\Request;
use Tests\TestCase;

class PluginExtensibilityPhase2Test extends TestCase
{
    protected function tearDown(): void
    {
        PluginCapabilityRegistry::reset();
        PluginAssetQueue::reset();
        parent::tearDown();
    }

    public function test_accept_ui_slot_accepts_runtime_without_legacy_component(): void
    {
        $plugin = [
            'slug' => 'demo-plugin',
            'path' => base_path('plugins/getfy-plugin-starter'),
            'frontend' => [
                'entry' => 'dist/plugin-ui.js',
                'manifest' => 'dist/ui.manifest.json',
                'exports' => ['settings' => 'SettingsTab'],
            ],
        ];

        $accepted = PluginRegistry::acceptUiSlot($plugin, [
            'id' => 'demo',
            'label' => 'Demo',
        ], 'settings');

        $this->assertIsArray($accepted);
        $this->assertSame('runtime', $accepted['ui_mode'] ?? null);
        $this->assertSame('SettingsTab', $accepted['ui_export'] ?? null);
    }

    public function test_plugin_capability_registry_accepts_rich_manifest(): void
    {
        PluginCapabilityRegistry::registerFromManifest([
            'slug' => 'rich-cap',
            'capabilities' => [
                ['id' => 'manage', 'label' => 'Gerenciar loja'],
            ],
        ]);

        $all = PluginCapabilityRegistry::all();
        $this->assertArrayHasKey('plugin:rich-cap:manage', $all);
        $this->assertSame('Gerenciar loja', $all['plugin:rich-cap:manage']);
    }

    public function test_middleware_registry_allows_team_permission_aliases(): void
    {
        $this->assertTrue(PluginMiddlewareRegistry::isAllowedForPanel('team.permission:equipe.manage'));
        PluginCapabilityRegistry::register('cap-plugin', ['view' => 'Ver']);
        $this->assertTrue(PluginMiddlewareRegistry::isAllowedForPanel('team.permission:plugin:cap-plugin:view'));
        $this->assertFalse(PluginMiddlewareRegistry::isAllowedForPanel('team.permission:plugin:unknown:view'));
    }

    public function test_asset_queue_context_detects_api_checkout(): void
    {
        $request = Request::create('/api-checkout/abc123', 'GET');
        $this->assertSame('checkout', PluginAssetQueue::contextForRequest($request));
    }

    public function test_render_zones_filter_hook(): void
    {
        $zones = PluginExtensionRegistry::getAllRenderZones();
        $this->assertIsArray($zones);
    }
}
