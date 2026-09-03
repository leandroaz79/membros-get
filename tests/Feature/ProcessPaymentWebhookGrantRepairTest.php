<?php

namespace Tests\Feature;

use App\Gateways\Stripe\StripeDriver;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class ProcessPaymentWebhookGrantRepairTest extends TestCase
{
    public function test_completed_order_receives_access_on_duplicate_paid_webhook(): void
    {
        $this->mock(StripeDriver::class, function ($mock) {
            $mock->shouldReceive('getTransactionStatus')->andReturn('paid');
        });

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $main = $this->createTestProduct(['name' => 'Main']);
        $bumpTarget = $this->createTestProduct(['name' => 'Bump target']);

        $cred = new GatewayCredential([
            'tenant_id' => 1,
            'gateway_slug' => 'stripe',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials(['secret_key' => 'sk_test_fake']);
        $cred->save();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 20,
            'email' => 'bump@test.com',
            'gateway' => 'stripe',
            'gateway_id' => 'pi_bump_repair',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 10,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpTarget->id,
            'amount' => 10,
            'position' => 1,
        ]);

        $this->assertFalse($main->users()->where('users.id', $user->id)->exists());
        $this->assertFalse($bumpTarget->users()->where('users.id', $user->id)->exists());

        ProcessPaymentWebhook::dispatchSync('stripe', 'pi_bump_repair', 'payment_intent.succeeded', 'paid', []);

        $this->assertTrue($main->users()->where('users.id', $user->id)->exists());
        $this->assertTrue($bumpTarget->users()->where('users.id', $user->id)->exists());
        $this->assertSame('completed', $order->fresh()->status);
    }
}
