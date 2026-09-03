<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PanelPushDedupTest extends TestCase
{
    public function test_duplicate_order_completed_uses_single_push_dedupe_key(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);
        Cache::flush();

        $product = $this->createTestProduct(['name' => 'Produto Dedup']);
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => $product->tenant_id,
        ]);

        PanelPushSubscription::create([
            'user_id' => $user->id,
            'tenant_id' => $product->tenant_id,
            'endpoint' => 'https://example.com/push/dedup-endpoint',
            'keys' => ['auth' => 'auth-key', 'p256dh' => 'p256dh-key'],
            'preferences' => ['pix' => true, 'boleto' => true, 'card' => true],
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);
        $order->setRelation('product', $product);

        event(new OrderCompleted($order));
        event(new OrderCompleted($order->fresh()));

        $this->assertSame(1, PanelNotification::where('event_key', 'sale_'.$order->id)->count());
        $this->assertTrue(Cache::has('panel_push_sent.'.$product->tenant_id.'.sale_'.$order->id));
    }
}
