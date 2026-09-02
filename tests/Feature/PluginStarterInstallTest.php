<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use App\Plugins\PluginRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginStarterInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_starter_manifest_registers_settings_tab_with_runtime_metadata(): void
    {
        $path = base_path('plugins/getfy-plugin-starter');
        if (! is_dir($path)) {
            $this->markTestSkipped('Starter ausente.');
        }

        Plugin::create([
            'slug' => 'getfy-plugin-starter',
            'name' => 'Starter',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);

        $tabs = PluginRegistry::getSettingsTabs();
        $starterTab = collect($tabs)->firstWhere('id', 'starter');
        if (! is_file($path.'/dist/ui.manifest.json')) {
            $this->assertNotNull($starterTab);
            $this->markTestSkipped('Sem dist: apenas manifest legado.');
        }
        $this->assertNotNull($starterTab);
        $this->assertSame('runtime', $starterTab['ui_mode'] ?? null);
        $this->assertSame('SettingsTab', $starterTab['ui_export'] ?? null);
    }

    public function test_validate_command_for_starter(): void
    {
        if (! is_dir(base_path('plugins/getfy-plugin-starter'))) {
            $this->markTestSkipped('Starter ausente.');
        }
        $this->artisan('plugin:validate', ['slug' => 'getfy-plugin-starter'])
            ->assertExitCode(is_file(base_path('plugins/getfy-plugin-starter/dist/ui.manifest.json')) ? 0 : 1);
    }
}
