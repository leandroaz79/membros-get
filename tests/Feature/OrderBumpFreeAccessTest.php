<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOrderBump;
use App\Models\User;
use Tests\TestCase;

class OrderBumpFreeAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_free_bump_creates_zero_amount_item_and_grants_target_access(): void
    {
        $tenantId = 1;
        $buyer = User::factory()->create([
            'tenant_id' => $tenantId,
            'role' => User::ROLE_ALUNO,
            'email' => 'buyer-free-bump@example.com',
        ]);

        $main = $this->createTestProduct([
            'tenant_id' => $tenantId,
            'name' => 'Produto principal',
            'price' => 97,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $bonus = $this->createTestProduct([
            'tenant_id' => $tenantId,
            'name' => 'Bônus grátis',
            'price' => 47,
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);

        $bump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $bonus->id,
            'title' => 'Leve o bônus',
            'description' => 'Oferta grátis',
            'cta_title' => 'Quero o bônus',
            'price_override' => 0,
            'is_free' => true,
            'position' => 1,
        ]);

        $this->assertSame(0.0, $bump->getEffectiveAmountBrl());
        $this->assertGreaterThan(0, $bump->getOriginalAmountBrl());

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $buyer->id,
            'product_id' => $main->id,
            'amount' => 97,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 97,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bonus->id,
            'product_order_bump_id' => $bump->id,
            'amount' => 0,
            'position' => 1,
        ]);

        $order->grantPurchasedProductAccessToBuyer();

        $this->assertTrue(
            $buyer->products()->where('products.id', $main->id)->exists(),
            'Buyer should have access to main product'
        );
        $this->assertTrue(
            $buyer->products()->where('products.id', $bonus->id)->exists(),
            'Buyer should have access to free bump target product'
        );
    }

    public function test_is_free_forces_effective_amount_zero_even_with_nonzero_override(): void
    {
        $main = $this->createTestProduct(['tenant_id' => 1, 'price' => 50]);
        $bonus = $this->createTestProduct(['tenant_id' => 1, 'price' => 30]);

        $bump = ProductOrderBump::create([
            'product_id' => $main->id,
            'target_product_id' => $bonus->id,
            'title' => 'Free',
            'cta_title' => 'Sim',
            'price_override' => 19.9,
            'is_free' => true,
            'position' => 1,
        ]);

        $this->assertSame(0.0, $bump->fresh()->getEffectiveAmountBrl());
    }
}
