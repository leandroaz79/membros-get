<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use Tests\TestCase;

class MemberAreaLoginTemplateTest extends TestCase
{
    public function test_login_page_defaults_to_template_v1(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'tplv1'.substr(md5(uniqid('', true)), 0, 4),
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'login' => [
                    'title' => 'Meu curso',
                ],
            ]),
        ]);

        $this->get('/m/'.$product->checkout_slug.'/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MemberAreaApp/Login')
                ->where('product.template', 'v1')
            );
    }

    public function test_login_page_exposes_template_v2_from_config(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'tplv2'.substr(md5(uniqid('', true)), 0, 4),
            'member_area_config' => array_replace_recursive(Product::defaultMemberAreaConfig(), [
                'login' => [
                    'template' => 'v2',
                    'title' => 'Curso premium',
                ],
            ]),
        ]);

        $this->get('/m/'.$product->checkout_slug.'/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MemberAreaApp/Login')
                ->where('product.template', 'v2')
                ->where('product.title', 'Curso premium')
            );
    }
}
