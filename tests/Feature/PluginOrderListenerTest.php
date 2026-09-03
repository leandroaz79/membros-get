<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Plugins\MercadoEnviosStub\Listeners\OnOrderCompleted;
use Tests\TestCase;

class PluginOrderListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_completed_listener_creates_shipment_row(): void
    {
        if (! is_dir(base_path('plugins/mercado-envios-stub'))) {
            $this->markTestSkipped('Plugin mercado-envios-stub ausente.');
        }

        $register = require base_path('plugins/mercado-envios-stub/bootstrap.php');
        if (is_callable($register)) {
            $register(app(), app('events'));
        }

        $this->artisan('migrate', [
            '--path' => 'plugins/mercado-envios-stub/migrations',
            '--force' => true,
        ]);

        if (! Schema::hasTable('mercado_envios_shipments')) {
            $this->markTestSkipped('Tabela mercado_envios_shipments não criada.');
        }

        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'buyer@example.com',
            'gateway' => 'pix',
        ]);
        $order->setRelation('product', $product);

        $listener = new OnOrderCompleted;
        $listener->handle(new OrderCompleted($order));

        $this->assertDatabaseHas('mercado_envios_shipments', [
            'order_id' => $order->id,
            'tenant_id' => $product->tenant_id,
        ]);
    }
}
