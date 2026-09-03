<?php

namespace Tests\Unit;

use App\Plugins\PluginExtensionRegistry;
use App\Plugins\PluginRegistry;
use Tests\TestCase;

class PluginExtensionRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        PluginExtensionRegistry::resetForTesting();
        parent::tearDown();
    }

    public function test_register_and_read_bootstrap_extension(): void
    {
        PluginExtensionRegistry::register('demo', ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], PluginExtensionRegistry::getBootstrapExtension('demo'));
    }

    public function test_starter_plugin_frontend_validates_when_dist_present(): void
    {
        $path = base_path('plugins/getfy-plugin-starter');
        if (! is_dir($path)) {
            $this->markTestSkipped('Starter plugin não presente.');
        }
        $manifest = PluginRegistry::readManifest($path);
        $this->assertIsArray($manifest);
        $plugin = array_merge($manifest, ['slug' => 'getfy-plugin-starter', 'path' => $path]);
        if (! is_file($path.'/dist/ui.manifest.json')) {
            $this->markTestSkipped('Execute npm run build no starter antes deste teste.');
        }
        $errors = PluginExtensionRegistry::validateFrontend($plugin);
        $this->assertSame([], $errors);
        $this->assertTrue(PluginExtensionRegistry::hasRuntimeFrontend($plugin));
    }

    public function test_inertia_payload_lists_runtime_plugins(): void
    {
        $path = base_path('plugins/getfy-plugin-starter');
        if (! is_file($path.'/dist/ui.manifest.json')) {
            $this->markTestSkipped('dist/ do starter ausente.');
        }
        $payload = PluginExtensionRegistry::inertiaPayload();
        $this->assertArrayHasKey('plugins', $payload);
    }
}
