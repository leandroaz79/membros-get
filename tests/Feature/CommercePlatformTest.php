<?php

namespace Tests\Feature;

use App\Models\StorefrontDomain;
use App\Models\User;
use App\Services\StorefrontDomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommercePlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_lists_tenant_products(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $this->createTestProduct([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Produto Loja',
        ]);

        $response = $this->getJson('/commerce/catalog/products?tenant_id='.$owner->tenant_id);
        $response->assertOk();
        $response->assertJsonPath('total', 1);
        $response->assertJsonFragment(['name' => 'Produto Loja']);
    }

    public function test_cart_add_line_and_checkout_start(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct([
            'tenant_id' => $owner->tenant_id,
            'price' => 99.90,
        ]);

        $this->postJson('/commerce/cart/lines?tenant_id='.$owner->tenant_id, [
            'product_id' => $product->id,
            'quantity' => 2,
        ])->assertOk()->assertJsonPath('totals.line_count', 1);

        $start = $this->postJson('/commerce/checkout/start?tenant_id='.$owner->tenant_id, [
            'customer' => ['email' => 'buyer@example.com', 'name' => 'Buyer'],
        ]);
        $start->assertOk();
        $start->assertJsonStructure(['checkout_url', 'order_id', 'session_token']);
    }

    public function test_storefront_domain_resolves_tenant_from_host(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        StorefrontDomainService::register($owner->tenant_id, 'loja.test', 'getfy-vitrine-demo', true);

        $domain = StorefrontDomain::where('host', 'loja.test')->first();
        $this->assertNotNull($domain);
        $this->assertSame($owner->tenant_id, $domain->tenant_id);

        $request = \Illuminate\Http\Request::create('https://loja.test/commerce/catalog/products');
        $request->headers->set('HOST', 'loja.test');
        $this->assertSame(
            $owner->tenant_id,
            \App\Services\StorefrontTenantResolver::tenantIdFromHost($request)
        );
    }
}
