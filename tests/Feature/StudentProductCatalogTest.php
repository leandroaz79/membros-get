<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use App\Services\StudentProductCatalogService;
use Tests\TestCase;

class StudentProductCatalogTest extends TestCase
{
    public function test_catalog_includes_member_area_and_link_products(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $member = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'cat'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Curso A',
        ]);
        $link = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'name' => 'Link B',
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'deliverable_link' => 'https://example.com/x',
            ]),
        ]);

        $member->users()->attach($user->id);
        $link->users()->attach($user->id);

        $catalog = app(StudentProductCatalogService::class)->catalogForUser($user);
        $ids = array_column($catalog, 'id');

        $this->assertContains($member->id, $ids);
        $this->assertContains($link->id, $ids);

        $memberItem = collect($catalog)->firstWhere('id', $member->id);
        $this->assertSame('member_area', $memberItem['access']['action']);

        $linkItem = collect($catalog)->firstWhere('id', $link->id);
        $this->assertSame('link', $linkItem['access']['action']);
    }

    public function test_meus_produtos_page_renders_for_aluno(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'pg'.substr(md5(uniqid('', true)), 0, 6),
        ]);
        $product->users()->attach($user->id);

        $this->actingAs($user)
            ->get('/meus-produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('MemberArea/Index')
                ->has('produtos', 1)
            );
    }

    public function test_area_membros_redirects_to_meus_produtos(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $this->actingAs($user)
            ->get('/area-membros')
            ->assertRedirect('/meus-produtos');
    }
}
