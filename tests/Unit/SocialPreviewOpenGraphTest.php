<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Support\SocialPreviewOpenGraph;
use Illuminate\Http\Request;
use Tests\TestCase;

class SocialPreviewOpenGraphTest extends TestCase
{
    public function test_member_area_uses_pwa_icon_for_preview(): void
    {
        $product = new Product([
            'name' => 'Curso Premium',
            'description' => 'Aprenda do zero',
            'type' => Product::TYPE_AREA_MEMBROS,
            'tenant_id' => 1,
            'member_area_config' => [
                'pwa' => [
                    'name' => 'Minha Área',
                    'icons' => [
                        ['src' => '/storage/member/icon-512.png', 'sizes' => '512x512'],
                        ['src' => '/storage/member/icon-192.png', 'sizes' => '192x192'],
                    ],
                ],
                'logos' => [
                    'favicon' => '/storage/member/favicon.png',
                ],
            ],
        ]);

        $request = Request::create('https://escola.test/m/curso', 'GET');

        $meta = SocialPreviewOpenGraph::forMemberArea($product, $request);

        $this->assertSame('Minha Área', $meta['title']);
        $this->assertSame('https://escola.test/storage/member/icon-512.png', $meta['image']);
        $this->assertSame('https://escola.test/storage/member/favicon.png', $meta['favicon']);
    }

    public function test_platform_uses_configured_pwa_icon(): void
    {
        config([
            'getfy.app_name' => 'Minha Plataforma',
            'getfy.pwa_icon_512' => '/icons/custom-512.png',
            'getfy.pwa_icon_192' => '/icons/custom-192.png',
        ]);

        $request = Request::create('https://painel.test/dashboard', 'GET');

        $meta = SocialPreviewOpenGraph::forPlatform($request);

        $this->assertSame('Minha Plataforma', $meta['title']);
        $this->assertSame('https://painel.test/icons/custom-512.png', $meta['image']);
    }
}
