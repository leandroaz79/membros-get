<?php

namespace Tests\Feature;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PluginFullPageRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_plugin_dashboard_route_renders_inertia_component(): void
    {
        $path = base_path('plugins/getfy-plugin-starter');
        if (! is_file($path.'/dist/ui.manifest.json')) {
            $this->markTestSkipped('Build dist/ do starter antes de executar.');
        }

        $user = User::factory()->create(['role' => 'admin']);
        Plugin::create([
            'slug' => 'getfy-plugin-starter',
            'name' => 'Starter',
            'version' => '1.1.0',
            'is_enabled' => true,
        ]);

        Route::middleware(['web', 'auth', 'role:admin|infoprodutor'])
            ->prefix('getfy-plugin-starter')
            ->group(base_path('plugins/getfy-plugin-starter/routes.php'));

        $response = $this->actingAs($user)->get('/getfy-plugin-starter/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('plugin_ui_page.ui_export', 'DashboardPage')
            ->where('pluginSlug', 'getfy-plugin-starter'));
    }
}
