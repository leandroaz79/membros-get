<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Events\SubscriptionCreated;
use App\Http\Middleware\EnsureInstalled;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\PixCheckoutDisplay;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CajuPayPixAutomaticTest extends TestCase
{
    private const WEBHOOK_SECRET = 'cwhsec_test_pix_auto_secret_123456789012';

    private const SUB_UUID = 'b2c3d4e5-f6a7-4890-b123-456789012345';

    private const PAYMENT_UUID = 'c3d4e5f6-a7b8-4901-c234-567890123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    private function seedCredential(int $tenantId = 1): void
    {
        $cred = new GatewayCredential([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'gpk_test',
            'secret_key' => 'gsk_test',
            'webhook_signing_secret' => self::WEBHOOK_SECRET,
        ]);
        $cred->save();
    }

    /**
     * @return array{0: Product, 1: SubscriptionPlan}
     */
    private function seedSubscriptionProduct(): array
    {
        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'type' => Product::TYPE_AREA_MEMBROS,
            'price' => 99,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => [
                    'pix_auto' => 'cajupay',
                ],
            ]),
        ]);
        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 99,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 1,
        ]);

        return [$product, $plan];
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array{raw: string, ts: string, sig: string}
     */
    private function signWebhook(string $type, array $object): array
    {
        $payload = [
            'id' => (string) Str::uuid(),
            'type' => $type,
            'data' => ['object' => $object],
        ];
        $raw = json_encode($payload);
        $ts = (string) time();
        $sig = hash_hmac('sha256', $ts.'.'.$raw, self::WEBHOOK_SECRET);

        return compact('raw', 'ts', 'sig');
    }

    public function test_checkout_pix_auto_cajupay_creates_subscription_and_returns_qr(): void
    {
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();

        Http::fake([
            '*/api/subscriptions' => Http::response([
                'id' => self::SUB_UUID,
                'subscription_id' => self::SUB_UUID,
                'correlation_id' => 'order-1',
                'status' => 'pending_approval',
                'pix_emv' => '00020126580014br.gov.bcb.pix0136test-copy-paste',
                'pix_copy_paste' => '00020126580014br.gov.bcb.pix0136test-copy-paste',
            ], 200),
        ]);

        $response = $this->postJson('/checkout', [
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'payment_method' => 'pix_auto',
            'email' => 'aluno@example.com',
            'name' => 'Aluno Teste',
            'cpf' => '52998224725',
            'phone' => '11999999999',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment_method', 'pix_auto')
            ->assertJsonPath('copy_paste', '00020126580014br.gov.bcb.pix0136test-copy-paste');

        $order = Order::query()->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('cajupay', $order->gateway);
        $this->assertSame(self::SUB_UUID, $order->gateway_id);
        $this->assertSame('pix_auto', $order->metadata['checkout_payment_method'] ?? null);
        $this->assertSame(self::SUB_UUID, $order->metadata['cajupay_subscription_id'] ?? null);
        $this->assertSame('pending', $order->status);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/subscriptions')
                && ($request['method'] ?? null) === 'pix_automatic'
                && ($request['frequency'] ?? null) === 'MONTHLY'
                && ($request['day_due'] ?? null) === 7
                && ($request['customer']['address']['zipcode'] ?? null) === '01310100'
                && ($request['customer']['address']['city'] ?? null) === 'São Paulo';
        });
    }

    public function test_subscription_approved_does_not_complete_order(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionCreated::class]);
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        $signed = $this->signWebhook('subscription.approved', [
            'subscription_id' => self::SUB_UUID,
            'correlation_id' => 'order-'.$order->id,
            'status' => 'approved',
        ]);

        $this->call(
            'POST',
            '/webhooks/gateways/cajupay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CAJUPAY_EVENT' => 'subscription.approved',
                'HTTP_X_CAJUPAY_TIMESTAMP' => $signed['ts'],
                'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$signed['ts'].',v1='.$signed['sig'],
            ],
            $signed['raw']
        )->assertOk();

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame('approved', $order->metadata['cajupay_subscription_status'] ?? null);
        Event::assertNotDispatched(OrderCompleted::class);
        Event::assertNotDispatched(SubscriptionCreated::class);
    }

    public function test_subscription_charge_paid_completes_order_and_creates_subscription(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionCreated::class]);
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        $signed = $this->signWebhook('subscription.charge.paid', [
            'subscription_id' => self::SUB_UUID,
            'correlation_id' => 'order-'.$order->id,
            'cajupay_payment_id' => self::PAYMENT_UUID,
            'id' => (string) Str::uuid(),
            'status' => 'paid',
            'amount_cents' => 9900,
        ]);

        $this->call(
            'POST',
            '/webhooks/gateways/cajupay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CAJUPAY_EVENT' => 'subscription.charge.paid',
                'HTTP_X_CAJUPAY_TIMESTAMP' => $signed['ts'],
                'HTTP_X_CAJUPAY_SIGNATURE' => 't='.$signed['ts'].',v1='.$signed['sig'],
            ],
            $signed['raw']
        )->assertOk();

        $order->refresh();
        $this->assertSame('completed', $order->status);

        $sub = Subscription::query()->where('gateway_subscription_id', self::SUB_UUID)->first();
        $this->assertNotNull($sub);
        $this->assertSame(Subscription::STATUS_ACTIVE, $sub->status);
        Event::assertDispatched(OrderCompleted::class);
        Event::assertDispatched(SubscriptionCreated::class);
    }

    public function test_assinaturas_show_returns_subscription_detail(): void
    {
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $sub = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
            'gateway_subscription_id' => self::SUB_UUID,
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        Http::fake([
            '*/api/subscriptions/'.self::SUB_UUID => Http::response(['status' => 'active', 'id' => self::SUB_UUID], 200),
            '*/api/subscriptions/'.self::SUB_UUID.'/charges' => Http::response([
                ['id' => (string) Str::uuid(), 'status' => 'paid', 'amount_cents' => 9900],
            ], 200),
        ]);

        $response = $this->actingAs($admin)->getJson(route('assinaturas.show', $sub->id));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('subscription.id', $sub->id)
            ->assertJsonPath('subscription.is_cajupay', true)
            ->assertJsonCount(1, 'subscription.charges');
    }

    public function test_assinaturas_cancel_calls_cajupay_api(): void
    {
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $sub = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
            'gateway_subscription_id' => self::SUB_UUID,
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        Http::fake([
            '*/api/subscriptions/'.self::SUB_UUID.'/cancel' => Http::response(['status' => 'cancelled'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson(route('assinaturas.cancel', $sub->id), [
            'revoke_access_now' => true,
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $sub->refresh();
        $this->assertSame(Subscription::STATUS_CANCELLED, $sub->status);

        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_contains($req->url(), '/api/subscriptions/'.self::SUB_UUID.'/cancel'));
    }

    public function test_assinaturas_refund_charge_calls_cajupay_api(): void
    {
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $admin = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_INFOPRODUTOR]);
        $buyer = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $chargeId = (string) Str::uuid();

        $sub = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subMonth()->toDateString(),
            'current_period_end' => now()->addMonth()->toDateString(),
            'gateway_subscription_id' => self::SUB_UUID,
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);

        Order::create([
            'tenant_id' => 1,
            'user_id' => $buyer->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $buyer->email,
            'status' => 'completed',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        Http::fake([
            '*/api/subscriptions/'.self::SUB_UUID.'/charges/'.$chargeId.'/refund' => Http::response(['status' => 'refunded'], 200),
        ]);

        $response = $this->actingAs($admin)->postJson(route('assinaturas.charges.refund', [$sub->id, $chargeId]));

        $response->assertOk()->assertJsonPath('success', true);
        Http::assertSent(fn ($req) => $req->method() === 'POST'
            && str_contains($req->url(), '/charges/'.$chargeId.'/refund'));
    }

    public function test_order_status_poll_reconciles_paid_pix_auto_subscription(): void
    {
        Event::fake([OrderCompleted::class, SubscriptionCreated::class]);
        $this->seedCredential();
        [$product, $plan] = $this->seedSubscriptionProduct();
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'amount' => 99,
            'currency' => 'BRL',
            'email' => $user->email,
            'status' => 'pending',
            'gateway' => 'cajupay',
            'gateway_id' => self::SUB_UUID,
            'metadata' => [
                'checkout_payment_method' => 'pix_auto',
                'cajupay_subscription_id' => self::SUB_UUID,
            ],
        ]);

        Http::fake([
            '*/api/subscriptions/'.self::SUB_UUID.'/sync' => Http::response(['status' => 'active'], 200),
            '*/api/subscriptions/'.self::SUB_UUID.'/charges' => Http::response([
                [
                    'id' => (string) Str::uuid(),
                    'status' => 'paid',
                    'amount_cents' => 9900,
                    'cajupay_payment_id' => self::PAYMENT_UUID,
                ],
            ], 200),
        ]);

        $token = PixCheckoutDisplay::storeSession($order, [
            'copy_paste' => '00020126580014br.gov.bcb.pix0136test-copy-paste',
        ]);

        $response = $this->getJson('/checkout/order-status?token='.$token);

        $response->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame('completed', $order->fresh()->status);
        Event::assertDispatched(OrderCompleted::class);
        Event::assertDispatched(SubscriptionCreated::class);
    }
}
