<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CheckoutAbuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['checkout_security.enabled' => true]);
        Cache::flush();
    }

    public function test_honeypot_blocks_checkout_without_creating_order(): void
    {
        $product = $this->createTestProduct();

        $response = $this->post('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'bot@example.com',
            'name' => 'Bot',
            'website' => 'http://spam.test',
        ]);

        $response->assertStatus(429);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_inactive_product_returns_not_found_on_checkout(): void
    {
        $product = $this->createTestProduct(['is_active' => false]);

        $response = $this->post('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'buyer@example.com',
            'name' => 'Buyer',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, Order::query()->count());
    }

    public function test_stale_pending_orders_command_cancels_old_orders_without_gateway(): void
    {
        $product = $this->createTestProduct();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);

        $order = Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'old@example.com',
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);
        $order->timestamps = false;
        $order->created_at = now()->subHours(30);
        $order->updated_at = now()->subHours(30);
        $order->save();

        $this->artisan('orders:cancel-stale-pending', ['--hours' => 24])
            ->assertSuccessful();

        $this->assertSame('cancelled', Order::query()->first()->status);
    }

    public function test_pending_limit_blocks_checkout_when_too_many_recent_pending_orders(): void
    {
        $product = $this->createTestProduct();
        $user = User::factory()->create(['role' => User::ROLE_ALUNO, 'tenant_id' => 1]);

        config(['checkout_security.pending.max_per_ip_hour' => 1]);

        Order::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'email' => 'limit@example.com',
            'customer_ip' => '203.0.113.10',
            'gateway' => null,
            'gateway_id' => null,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post('/checkout', [
            'product_id' => $product->id,
            'payment_method' => 'pix',
            'email' => 'limit2@example.com',
            'name' => 'Limit User',
            'cpf' => '52998224725',
            'phone' => '11999999999',
        ]);

        $response->assertStatus(429);
        $this->assertSame(1, Order::query()->count());
    }
}
