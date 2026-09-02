<?php

namespace Tests\Feature;

use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Plugins\Commerce\CommerceCheckoutContextRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceCheckoutContextRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CommerceCheckoutContextRegistry::register('test-registry-plugin', []);
        parent::tearDown();
    }

    public function test_registry_resolves_label_summary_and_line_items(): void
    {
        CommerceCheckoutContextRegistry::register('test-registry-plugin', [
            'supports' => fn (Order $order): bool => true,
            'order_label' => fn (): string => 'Loja Teste — 2 itens',
            'line_items' => fn (): array => [
                ['name' => 'Item A', 'quantity' => 1, 'amount' => 40.0],
            ],
            'payment_summary' => fn (): array => [
                ['label' => 'Subtotal', 'amount' => 40.0],
                ['label' => 'Frete', 'amount' => 10.0],
                ['label' => 'Total', 'amount' => 50.0, 'highlight' => true],
            ],
        ]);

        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@example.com',
            'metadata' => ['plugin_checkout' => 'test-registry-plugin'],
        ]);

        $session = CommerceCheckoutSession::create([
            'tenant_id' => $order->tenant_id,
            'session_token' => 'tok-test',
            'order_id' => $order->id,
            'amount' => 50,
            'currency' => 'BRL',
            'customer' => ['email' => 'buyer@example.com'],
            'line_items' => [],
            'expires_at' => now()->addHour(),
        ]);

        $this->assertTrue(CommerceCheckoutContextRegistry::supports($order));
        $this->assertSame('Loja Teste — 2 itens', CommerceCheckoutContextRegistry::resolveOrderLabel($order));
        $this->assertCount(1, CommerceCheckoutContextRegistry::resolveLineItems($order) ?? []);
        $summary = CommerceCheckoutContextRegistry::resolvePaymentSummary($order, $session);
        $this->assertNotNull($summary);
        $this->assertCount(3, $summary);
    }
}
