<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Jobs\DispatchWebhookJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\Webhook;
use App\Support\WebhookPayloadBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookPayloadBuilderTest extends TestCase
{
    public function test_order_payload_includes_offer_and_product_details(): void
    {
        $product = $this->createTestProduct([
            'name' => 'Curso Premium',
            'checkout_slug' => 'curso-premium',
        ]);

        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'name' => 'Oferta Black Friday',
            'price' => 149.90,
            'currency' => 'BRL',
            'position' => 0,
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
        ]);

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offer->id,
            'status' => 'completed',
            'amount' => 149.90,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'cpf' => '12345678900',
            'gateway' => 'cajupay',
            'metadata' => [
                'checkout_payment_method' => 'pix',
                'utm_source' => 'meta',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('Curso Premium', $payload['product']['name']);
        $this->assertSame('curso-premium', $payload['product']['checkout_slug']);
        $this->assertSame('Oferta Black Friday', $payload['offer']['name']);
        $this->assertSame($offer->id, $payload['offer']['id']);
        $this->assertSame($offer->public_id, $payload['offer']['public_id']);
        $this->assertSame('exclusive_checkout', $payload['offer']['offer_type']);
        $this->assertSame($offer->id, $payload['order']['product_offer_id']);
        $this->assertStringContainsString('/c/', $payload['checkoutUrl']);
        $this->assertStringNotContainsString('offer=', $payload['checkoutUrl']);
        $this->assertSame('pix', $payload['payment']['method']);
        $this->assertSame('meta', $payload['tracking']['utm_source']);
        $this->assertSame('buyer@test.com', $payload['customer']['email']);
        $this->assertSame('12345678900', $payload['customer']['docNumber']);
        $this->assertSame('cpf', $payload['customer']['docType']);
        $this->assertArrayNotHasKey('email_hash', $payload['customer']);
        $this->assertArrayNotHasKey('name_hash', $payload['customer']);
        $this->assertSame('paid', $payload['status']);
        $this->assertSame('pix', $payload['paymentMethod']);
        $this->assertSame('Pix', $payload['paymentMethodName']);
        $this->assertArrayHasKey('checkoutUrl', $payload);
        $this->assertArrayNotHasKey('order_bumps', $payload);
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertArrayNotHasKey('email', $payload['order']);
    }

    public function test_checkout_url_includes_offer_token_for_variant_offers(): void
    {
        $product = $this->createTestProduct([
            'name' => 'Produto multi-oferta',
            'checkout_slug' => 'multi-oferta',
        ]);

        $offerA = ProductOffer::create([
            'product_id' => $product->id,
            'public_id' => 'OfferTokenA',
            'name' => 'Oferta A',
            'price' => 100,
            'currency' => 'BRL',
            'position' => 0,
            'checkout_slug' => '',
        ]);

        $offerB = ProductOffer::create([
            'product_id' => $product->id,
            'public_id' => 'OfferTokenB',
            'name' => 'Oferta B',
            'price' => 200,
            'currency' => 'BRL',
            'position' => 1,
            'checkout_slug' => ProductOffer::generateUniqueCheckoutSlug(),
        ]);

        $orderA = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offerA->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'BRL',
            'email' => 'a@test.com',
        ]);

        $orderB = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offerB->id,
            'status' => 'completed',
            'amount' => 200,
            'currency' => 'BRL',
            'email' => 'b@test.com',
        ]);

        $payloadA = WebhookPayloadBuilder::forOrderEvent($orderA);
        $payloadB = WebhookPayloadBuilder::forOrderEvent($orderB);

        $this->assertSame('OfferTokenA', $payloadA['offer']['public_id']);
        $this->assertSame('OfferTokenB', $payloadB['offer']['public_id']);
        $this->assertSame('variant', $payloadA['offer']['offer_type']);
        $this->assertSame('exclusive_checkout', $payloadB['offer']['offer_type']);
        $this->assertStringContainsString('offer=OfferTokenA', $payloadA['checkoutUrl']);
        $this->assertStringNotContainsString('offer=', $payloadB['checkoutUrl']);
        $this->assertNotSame($payloadA['checkoutUrl'], $payloadB['checkoutUrl']);
    }

    public function test_cart_abandoned_payload_tracks_selected_offer(): void
    {
        $product = $this->createTestProduct(['checkout_slug' => 'abandono-slug']);

        $offer = ProductOffer::create([
            'product_id' => $product->id,
            'public_id' => 'AbandonOffer1',
            'name' => 'Oferta recuperação',
            'price' => 79.9,
            'currency' => 'BRL',
            'position' => 0,
            'checkout_slug' => '',
        ]);

        $session = CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'product_offer_id' => $offer->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_FORM_STARTED,
            'email' => 'lead@test.com',
            'name' => 'Lead Teste',
        ]);

        $payload = WebhookPayloadBuilder::forCartAbandoned($session);

        $this->assertSame($offer->id, $payload['checkout_session']['product_offer_id']);
        $this->assertSame('AbandonOffer1', $payload['offer']['public_id']);
        $this->assertStringContainsString('offer=AbandonOffer1', $payload['checkoutUrl']);
    }

    public function test_order_payload_includes_order_bump_line_items(): void
    {
        $main = $this->createTestProduct(['name' => 'Produto principal']);
        $bumpProduct = $this->createTestProduct(['name' => 'Order bump extra']);

        $order = Order::create([
            'tenant_id' => $main->tenant_id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 120,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'gateway' => 'cajupay',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 100,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order->fresh(['orderItems.product']));

        $this->assertCount(1, $payload['order_bumps']);
        $this->assertSame('Order bump extra', $payload['order_bumps'][0]['name']);
        $this->assertSame(20.0, $payload['order_bumps'][0]['amount']);
        $this->assertSame('Produto principal', $payload['product']['name']);
    }

    public function test_tracking_merges_checkout_session_utms(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [],
        ]);

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'utm_source' => 'google',
            'utm_campaign' => 'spring',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('google', $payload['tracking']['utm_source']);
        $this->assertSame('spring', $payload['tracking']['utm_campaign']);
    }

    public function test_tracking_includes_fbc_fbp_sck_from_session(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'metadata' => [
                'utm_source' => 'meta',
                'custom_field' => 'ignored',
            ],
        ]);

        CheckoutSession::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'checkout_slug' => $product->checkout_slug,
            'session_token' => 'tok-'.uniqid('', true),
            'step' => CheckoutSession::STEP_CONVERTED,
            'order_id' => $order->id,
            'tracking_metadata' => [
                'fbp' => 'fb.1.example',
                'fbc' => 'fb.1.click',
                'sck' => 'sck-value',
                'gclid' => 'abc123',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('meta', $payload['tracking']['utm_source']);
        $this->assertSame('abc123', $payload['tracking']['gclid']);
        $this->assertSame('fb.1.example', $payload['tracking']['fbp']);
        $this->assertSame('fb.1.click', $payload['tracking']['fbc']);
        $this->assertSame('sck-value', $payload['tracking']['sck']);
        $this->assertSame('fb.1.example', $payload['fbp']);
        $this->assertArrayNotHasKey('custom_field', $payload['tracking']);
    }

    public function test_subscription_payload_includes_lifecycle_fields(): void
    {
        $product = $this->createTestProduct([
            'billing_type' => Product::BILLING_SUBSCRIPTION,
            'checkout_config' => array_merge(Product::defaultCheckoutConfig(), [
                'subscription' => [
                    'grace_period_days' => 2,
                    'notify_days_before' => 3,
                    'renewal_window_days' => 7,
                ],
            ]),
        ]);

        $plan = SubscriptionPlan::create([
            'product_id' => $product->id,
            'name' => 'Mensal',
            'price' => 50,
            'currency' => 'BRL',
            'interval' => SubscriptionPlan::INTERVAL_MONTHLY,
            'checkout_slug' => SubscriptionPlan::generateUniqueCheckoutSlug(),
            'position' => 1,
        ]);

        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $periodEnd = Carbon::today()->subDays(1);

        $subscription = Subscription::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'subscription_plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'current_period_start' => $periodEnd->copy()->subMonth()->toDateString(),
            'current_period_end' => $periodEnd->toDateString(),
            'renewal_token' => Subscription::generateRenewalToken(),
        ]);

        $payload = WebhookPayloadBuilder::forSubscriptionEvent($subscription);

        $this->assertSame(Subscription::STATUS_PAST_DUE, $payload['subscription']['status']);
        $this->assertArrayHasKey('access_until', $payload['subscription']);
        $this->assertArrayHasKey('renewable_until', $payload['subscription']);
        $this->assertArrayHasKey('days_overdue', $payload['subscription']);
        $this->assertSame($periodEnd->copy()->addDays(2)->toDateString(), $payload['subscription']['access_until']);
        $this->assertArrayNotHasKey('products', $payload);
        $this->assertSame('Mensal', $payload['subscription_plan']['name']);
    }

    public function test_denied_keys_never_appear_in_payload(): void
    {
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'customer_ip' => '203.0.113.50',
            'metadata' => [
                'customer_ip' => '203.0.113.50',
                'server_ip' => '10.0.0.1',
                'utm_source' => 'ads',
            ],
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);
        $encoded = json_encode($payload);

        $this->assertStringNotContainsString('203.0.113.50', (string) $encoded);
        $this->assertStringNotContainsString('10.0.0.1', (string) $encoded);
        $this->assertArrayNotHasKey('customer_ip', $payload);
        $this->assertSame('ads', $payload['tracking']['utm_source']);
    }

    public function test_pix_webhook_omits_huge_qrcode_image(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order, [
            'pix' => [
                'qrcode' => 'data:image/png;base64,'.str_repeat('A', 3000),
                'copy_paste' => '00020126580014br.gov.bcb.pix',
                'transaction_id' => 'tx-1',
            ],
        ]);

        $this->assertArrayNotHasKey('qrcode', $payload['pix']);
        $this->assertSame('00020126580014br.gov.bcb.pix', $payload['pix']['copy_paste']);
        $this->assertSame('00020126580014br.gov.bcb.pix', $payload['pix']['qrCode']);
    }

    public function test_access_extra_strips_password(): void
    {
        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order, [
            'access' => [
                'type' => 'member_area',
                'link' => 'https://example.com/m/slug',
                'password' => 'secret123',
            ],
        ]);

        $this->assertSame('member_area', $payload['access']['type']);
        $this->assertArrayNotHasKey('password', $payload['access']);
    }

    public function test_meta_compatible_hashes_for_facebook_style_integrations(): void
    {
        config(['getfy.webhooks.include_customer_hashes' => true]);

        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'Buyer@Email.COM',
            'phone' => '(11) 98888-7777',
            'cpf' => '123.456.789-00',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame(hash('sha256', 'buyer@email.com'), $payload['customer']['email_hash']);
        $this->assertSame(hash('sha256', '5511988887777'), $payload['customer']['phone_hash']);
        $this->assertSame(hash('sha256', '12345678900'), $payload['customer']['cpf_hash']);
    }

    public function test_customer_hashes_only_when_explicitly_enabled(): void
    {
        config(['getfy.webhooks.include_customer_hashes' => false]);

        $product = $this->createTestProduct();
        $order = Order::create([
            'tenant_id' => $product->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'currency' => 'BRL',
            'email' => 'buyer@test.com',
            'cpf' => '12345678900',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);

        $this->assertSame('buyer@test.com', $payload['customer']['email']);
        $this->assertArrayNotHasKey('email_hash', $payload['customer']);

        config(['getfy.webhooks.include_customer_hashes' => true]);
        $payloadWithHashes = WebhookPayloadBuilder::forOrderEvent($order->fresh());
        $this->assertSame(hash('sha256', 'buyer@test.com'), $payloadWithHashes['customer']['email_hash']);
    }

    public function test_sample_test_payload_includes_plain_customer(): void
    {
        $payload = WebhookPayloadBuilder::sampleTestPayload('pedido_pago');

        $this->assertTrue($payload['test'] ?? false);
        $this->assertSame('exemplo@email.com', $payload['customer']['email']);
        $this->assertSame('Cliente Exemplo', $payload['customer']['name']);
        $this->assertSame('paid', $payload['status']);
        $this->assertSame('pix', $payload['paymentMethod']);
        $this->assertSame('B8BcHrY', $payload['offer']['public_id']);
        $this->assertStringContainsString('offer=B8BcHrY', $payload['checkoutUrl']);
    }

    public function test_dispatch_webhook_job_sends_product_name(): void
    {
        Http::fake(['https://hook.example/*' => Http::response('ok', 200)]);

        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => $owner->tenant_id, 'name' => 'Webhook Product']);

        $webhook = Webhook::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Test hook',
            'url' => 'https://hook.example/callback',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        $order = Order::create([
            'tenant_id' => $owner->tenant_id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 99,
            'currency' => 'BRL',
            'email' => 'hook@test.com',
        ]);

        $payload = WebhookPayloadBuilder::forOrderEvent($order);
        (new DispatchWebhookJob($webhook->id, OrderCompleted::class, $payload))->handle();

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['payload']['product']['name'] ?? null) === 'Webhook Product'
                && $body['event'] === 'pedido_pago';
        });
    }
}
