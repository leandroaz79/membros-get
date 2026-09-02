<?php

namespace Tests\Unit;

use App\Support\PwaIcon;
use Tests\TestCase;

class PwaIconTest extends TestCase
{
    public function test_is_default_path_detects_platform_defaults(): void
    {
        $this->assertTrue(PwaIcon::isDefaultPath('/icons/icon.png'));
        $this->assertTrue(PwaIcon::isDefaultPath('/icons/icon-192x192.png'));
        $this->assertFalse(PwaIcon::isDefaultPath('/storage/white-label/1/icon-192.png'));
        $this->assertFalse(PwaIcon::isDefaultPath('https://cdn.example.com/pwa.png'));
    }

    public function test_custom_public_url_ignores_default_paths(): void
    {
        config([
            'getfy.pwa_icon_192' => '/icons/icon.png',
            'getfy.pwa_icon_512' => '/storage/white-label/1/icon-512.png',
        ]);

        $this->assertNull(PwaIcon::customPublicUrl('192'));
        $this->assertStringContainsString(
            '/storage/white-label/1/icon-512.png',
            (string) PwaIcon::customPublicUrl('512')
        );
    }

    public function test_manifest_icons_include_custom_urls(): void
    {
        config([
            'getfy.pwa_icon_192' => '/storage/white-label/1/icon-192.png',
            'getfy.pwa_icon_512' => '/storage/white-label/1/icon-512.png',
        ]);

        $icons = PwaIcon::manifestIcons();
        $sources = array_column($icons, 'src');

        $this->assertContains(url('/storage/white-label/1/icon-192.png'), $sources);
        $this->assertContains(url('/storage/white-label/1/icon-512.png'), $sources);
    }
}
