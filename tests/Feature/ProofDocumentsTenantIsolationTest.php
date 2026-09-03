<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProofDocumentsTenantIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_owner_can_view_own_order_proof(): void
    {
        $tenantId = 1;
        $owner = User::factory()->create(['tenant_id' => $tenantId, 'role' => User::ROLE_INFOPRODUTOR]);
        $product = $this->createTestProduct([
            'tenant_id' => $tenantId,
            'name' => 'Curso A',
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'product_id' => $product->id,
            'amount' => 99.9,
            'currency' => 'BRL',
            'email' => 'buyer@example.com',
            'cpf' => '12345678909',
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        $this->actingAs($owner)
            ->get("/vendas/{$order->id}/comprovacao")
            ->assertOk();
    }

    public function test_other_tenant_cannot_view_order_proof(): void
    {
        $product = $this->createTestProduct([
            'tenant_id' => 1,
            'name' => 'Curso A',
            'type' => Product::TYPE_AREA_MEMBROS,
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => null,
            'product_id' => $product->id,
            'amount' => 99.9,
            'currency' => 'BRL',
            'email' => 'secret@example.com',
            'cpf' => '12345678909',
            'status' => 'completed',
            'gateway' => 'manual',
        ]);

        $attacker = User::factory()->create(['tenant_id' => 2, 'role' => User::ROLE_INFOPRODUTOR]);

        $this->actingAs($attacker)
            ->get("/vendas/{$order->id}/comprovacao")
            ->assertNotFound();

        $this->actingAs($attacker)
            ->post("/vendas/{$order->id}/comprovacao/gerar")
            ->assertNotFound();

        $this->actingAs($attacker)
            ->get("/vendas/{$order->id}/comprovacao/pdf")
            ->assertNotFound();
    }
}
