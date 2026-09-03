<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberInternalProduct;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class MemberAreaInternalProductAccessTest extends TestCase
{
    public function test_loja_payload_includes_open_url_for_purchased_internal_product(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $hostSlug = 'loja'.substr(md5(uniqid('', true)), 0, 6);
        $host = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hostSlug,
        ]);

        $extraSlug = 'extra'.substr(md5(uniqid('', true)), 0, 6);
        $extra = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $extraSlug,
            'name' => 'Extra curso',
        ]);

        MemberInternalProduct::create([
            'product_id' => $host->id,
            'related_product_id' => $extra->id,
            'position' => 1,
        ]);

        $aluno = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $host->users()->attach($aluno->id);
        $extra->users()->attach($aluno->id);

        $response = $this->actingAs($aluno)->get('/m/'.$hostSlug.'/loja');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('MemberAreaApp/Loja')
            ->has('items', 1)
            ->where('items.0.has_access', true)
            ->where('items.0.open_url', fn ($url) => is_string($url) && (
                str_contains($url, '/products/'.$extra->id.'/open')
                || str_contains($url, '/m/'.$extraSlug)
            ))
        );
    }
}
