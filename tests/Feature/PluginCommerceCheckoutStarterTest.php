<?php

namespace Tests\Feature;

use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Plugins\Commerce\PluginCommerceCheckoutStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginCommerceCheckoutStarterTest extends TestCase
{
    use RefreshDatabase;

    public function test_starts_checkout_with_custom_amount_and_metadata(): void
    {
        $result = app(PluginCommerceCheckoutStarter::class)->start(
            tenantId: 1,
            pluginSlug: 'test-plugin',
            customer: ['email' => 'buyer@example.com', 'name' => 'Buyer'],
            amount: 104.90,
            currency: 'BRL',
            metadata: ['shipping_amount' => 15.90],
            lineItems: [
                ['name' => 'Camiseta', 'quantity' => 1, 'amount' => 89.00],
            ],
        );

        $this->assertArrayHasKey('checkout_url', $result);
        $this->assertStringContainsString('/commerce/checkout/', $result['checkout_url']);

        $order = Order::find($result['order_id']);
        $this->assertNotNull($order);
        $this->assertSame('104.90', (string) $order->amount);
        $this->assertSame('test-plugin', $order->metadata['plugin_checkout'] ?? null);
        $this->assertSame(15.90, $order->metadata['shipping_amount'] ?? null);

        $session = CommerceCheckoutSession::where('session_token', $result['session_token'])->first();
        $this->assertNotNull($session);
        $this->assertSame('104.90', (string) $session->amount);
    }

    public function test_aborts_when_commerce_checkout_building_listener_sets_abort(): void
    {
        $this->app['events']->listen(
            \App\Events\CommerceCheckoutBuilding::class,
            function (\App\Events\CommerceCheckoutBuilding $event): void {
                $event->abort = 'Estoque insuficiente';
            }
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            app(PluginCommerceCheckoutStarter::class)->start(
                tenantId: 1,
                pluginSlug: 'test-plugin',
                customer: ['email' => 'buyer@example.com'],
                amount: 50,
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }
}
