<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use Tests\TestCase;

class PanelPwaManifestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_manifest_uses_custom_white_label_icons(): void
    {
        config([
            'getfy.app_name' => 'Minha Marca',
            'getfy.pwa_icon_192' => '/storage/white-label/1/icon-192.png',
            'getfy.pwa_icon_512' => '/storage/white-label/1/icon-512.png',
        ]);

        $response = $this->get('/manifest.json');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/manifest+json');
        $response->assertJson([
            'name' => 'Minha Marca',
            'short_name' => 'Minha Marca',
        ]);

        $sources = array_column($response->json('icons'), 'src');
        $this->assertNotEmpty($sources);
        $this->assertTrue(
            collect($sources)->contains(fn (string $src) => str_contains($src, '/storage/white-label/1/icon-192.png'))
        );
        $this->assertTrue(
            collect($sources)->contains(fn (string $src) => str_contains($src, '/storage/white-label/1/icon-512.png'))
        );
        $this->assertFalse(
            collect($sources)->contains(fn (string $src) => str_contains($src, 'icon-192x192.png'))
        );
    }

    public function test_manifest_does_not_return_hardcoded_static_getfy_name_when_branded(): void
    {
        config([
            'getfy.app_name' => 'Plataforma XYZ',
            'getfy.pwa_icon_192' => 'https://cdn.example.com/pwa-192.png',
            'getfy.pwa_icon_512' => 'https://cdn.example.com/pwa-512.png',
        ]);

        $response = $this->get('/manifest.json');

        $response->assertStatus(200);
        $response->assertJsonMissing(['name' => 'Getfy']);
        $response->assertJsonFragment(['name' => 'Plataforma XYZ']);
        $response->assertJsonFragment(['short_name' => 'Plataforma X']);

        $sources = array_column($response->json('icons'), 'src');
        $this->assertContains('https://cdn.example.com/pwa-192.png', $sources);
        $this->assertContains('https://cdn.example.com/pwa-512.png', $sources);
    }

    public function test_manifest_short_name_truncates_long_app_name(): void
    {
        config([
            'getfy.app_name' => 'Minha Plataforma Incrível',
            'getfy.pwa_icon_192' => '/icons/icon.png',
            'getfy.pwa_icon_512' => '/icons/icon.png',
        ]);

        $response = $this->get('/manifest.json');

        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Minha Plataforma Incrível']);
        $response->assertJsonFragment(['short_name' => 'Minha Plataf']);
    }

    public function test_manifest_falls_back_to_default_icons_without_white_label(): void
    {
        config([
            'getfy.app_name' => 'Getfy',
            'getfy.pwa_icon_192' => '/icons/icon.png',
            'getfy.pwa_icon_512' => '/icons/icon.png',
        ]);

        $response = $this->get('/manifest.json');

        $response->assertStatus(200);
        $sources = array_column($response->json('icons'), 'src');
        $this->assertTrue(
            collect($sources)->contains(fn (string $src) => str_contains($src, '/icons/icon.png'))
        );
    }

    public function test_static_manifest_file_is_not_served(): void
    {
        $this->assertFileDoesNotExist(public_path('manifest.json'));
    }

    public function test_panel_pages_use_fixed_viewport_to_prevent_zoom(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('maximum-scale=1', false);
        $response->assertSee('user-scalable=no', false);
        $response->assertSee('class="panel-app"', false);
    }
}
