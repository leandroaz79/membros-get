<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use App\Models\User;
use App\Plugins\Commerce\CommerceCheckoutContextRegistry;
use App\Plugins\Commerce\PluginCommerceCheckoutStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\GetfyLojaFisica\Listeners\OnOrderCompleted;
use Plugins\GetfyLojaFisica\Models\PhysicalProduct;
use Plugins\GetfyLojaFisica\Models\PhysicalShipment;
use Plugins\GetfyLojaFisica\Models\ShippingRule;
use Plugins\GetfyLojaFisica\Models\ShippingStore;
use Plugins\GetfyLojaFisica\Services\ShippingQuoteService;
use Plugins\GetfyLojaFisica\Support\OrderPresentation;
use Tests\TestCase;

class GetfyLojaFisicaPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $register = require base_path('plugins/getfy-loja-fisica/bootstrap.php');
        if (is_callable($register)) {
            $register(app(), app('events'));
        }
        $this->artisan('migrate', [
            '--path' => 'plugins/getfy-loja-fisica/migrations',
            '--force' => true,
        ]);
    }

    public function test_shipping_quote_returns_options(): void
    {
        $store = ShippingStore::create([
            'tenant_id' => 1,
            'name' => 'Loja SP',
            'is_active' => true,
            'is_default' => true,
        ]);
        ShippingRule::create([
            'shipping_store_id' => $store->id,
            'name' => 'Todo Brasil',
            'match_type' => 'all',
            'price' => 19.90,
            'is_active' => true,
        ]);

        $options = app(ShippingQuoteService::class)->quoteForStore($store, '01310100');
        $this->assertCount(1, $options);
        $this->assertSame(19.90, $options[0]['price']);
    }

    public function test_checkout_start_includes_shipping_in_amount(): void
    {
        $result = app(PluginCommerceCheckoutStarter::class)->start(
            tenantId: 1,
            pluginSlug: 'getfy-loja-fisica',
            customer: ['email' => 'cliente@test.com', 'name' => 'Cliente'],
            amount: 109.90,
            metadata: [
                'shipping_amount' => 19.90,
                'physical_lines' => [
                    ['name' => 'Camiseta', 'quantity' => 1, 'amount' => 90.0, 'physical_product_id' => 1],
                ],
            ],
            lineItems: [['name' => 'Camiseta', 'quantity' => 1, 'amount' => 90.0]],
        );

        $order = Order::find($result['order_id']);
        $this->assertNotNull($order);
        $this->assertSame('109.90', (string) $order->amount);
        $this->assertSame('getfy-loja-fisica', $order->metadata['plugin_checkout'] ?? null);

        $summary = OrderPresentation::paymentSummary($order);
        $this->assertCount(3, $summary);
        $this->assertSame(19.90, $summary[1]['amount']);
    }

    public function test_order_completed_creates_shipment_and_decrements_stock(): void
    {
        $product = PhysicalProduct::create([
            'tenant_id' => 1,
            'name' => 'Boné',
            'slug' => 'bone',
            'price' => 50,
            'stock' => 5,
            'is_active' => true,
        ]);

        $shell = $this->createTestProduct(['tenant_id' => 1]);
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $shell->id,
            'status' => 'completed',
            'amount' => 69.90,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [
                'plugin_checkout' => 'getfy-loja-fisica',
                'shipping_amount' => 19.90,
                'physical_lines' => [
                    ['name' => 'Boné', 'quantity' => 2, 'amount' => 100, 'physical_product_id' => $product->id],
                ],
            ],
        ]);

        (new OnOrderCompleted)->handle(new OrderCompleted($order));

        $this->assertDatabaseHas('physical_shipments', ['order_id' => $order->id]);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_registry_resolves_order_label(): void
    {
        $shell = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => 1,
            'product_id' => $shell->id,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'a@b.com',
            'metadata' => [
                'plugin_checkout' => 'getfy-loja-fisica',
                'physical_lines' => [['name' => 'A', 'quantity' => 1, 'amount' => 50]],
            ],
        ]);

        $this->assertStringContainsString('Loja Física', CommerceCheckoutContextRegistry::resolveOrderLabel($order) ?? '');
    }
}
