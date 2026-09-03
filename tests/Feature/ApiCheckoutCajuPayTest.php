<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiCheckoutCajuPayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_api_checkout_show_includes_wallets_when_cajupay_configured(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $this->createCajupayCredential(1);

        $app = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'App Test',
            'slug' => 'app-test-'.uniqid(),
            'api_key_hash' => password_hash('test-key', PASSWORD_BCRYPT),
            'payment_gateways' => [
                'card' => 'cajupay',
                'apple_pay' => 'cajupay',
                'google_pay' => 'cajupay',
                'pix' => null,
            ],
            'is_active' => true,
        ]);

        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => 1,
            'session_token' => 'api-sess-'.str_repeat('a', 32),
            'customer' => ['email' => 'buyer@test.com', 'name' => 'Buyer', 'cpf' => '12345678901'],
            'amount' => 99.90,
            'currency' => 'BRL',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->get(route('api-checkout.show', ['token' => $session->session_token]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ApiCheckout/Show')
            ->has('checkout_payment_methods', 3)
            ->where('available_methods', function ($methods) {
                $ids = is_array($methods) ? $methods : $methods->all();

                return in_array('card', $ids, true)
                    && in_array('apple_pay', $ids, true)
                    && in_array('google_pay', $ids, true);
            })
        );
    }

    public function test_api_cajupay_session_returns_token(): void
    {
        Http::fake([
            '*/api/sdk/v1/checkout/sessions' => Http::response([
                'token' => 'tok_api_public',
                'checkout_session_id' => 'sess-api-1',
                'methods_available' => ['card', 'apple_pay', 'google_pay'],
            ], 201),
            '*/api/sdk/public/checkout/sessions/*' => Http::response([
                'methods_available' => ['card', 'apple_pay', 'google_pay'],
            ], 200),
        ]);

        $this->createCajupayCredential(1);
        $app = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'App',
            'slug' => 'app-'.uniqid(),
            'api_key_hash' => password_hash('k', PASSWORD_BCRYPT),
            'payment_gateways' => ['card' => 'cajupay', 'apple_pay' => 'cajupay', 'google_pay' => 'cajupay'],
            'is_active' => true,
        ]);
        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => 1,
            'session_token' => 'tok-'.str_repeat('b', 32),
            'customer' => ['email' => 'a@b.com', 'name' => 'A', 'cpf' => '52998224725'],
            'amount' => 50,
            'currency' => 'BRL',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->postJson(route('api-checkout.cajupay.session'), [
            'session_token' => $session->session_token,
            'payment_method' => 'apple_pay',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('token', 'tok_api_public');
        $this->assertNotEmpty($response->json('polling_token'));
    }

    public function test_api_cajupay_confirm_order_creates_pending_order(): void
    {
        $product = $this->createTestProduct(['price' => 120]);
        $this->createCajupayCredential(1);
        $app = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'App',
            'slug' => 'app-'.uniqid(),
            'api_key_hash' => password_hash('k', PASSWORD_BCRYPT),
            'payment_gateways' => ['card' => 'cajupay'],
            'is_active' => true,
        ]);
        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => 1,
            'session_token' => 'tok-'.str_repeat('c', 32),
            'product_id' => $product->id,
            'customer' => ['email' => 'pay@test.com', 'name' => 'Pay', 'cpf' => '12345678901'],
            'amount' => 120,
            'currency' => 'BRL',
            'expires_at' => now()->addHour(),
        ]);

        $pollingToken = str_repeat('d', 32);
        \Illuminate\Support\Facades\Cache::put('api_cajupay_draft.'.$pollingToken, [
            'api_checkout_session_id' => $session->id,
            'session_token' => $session->session_token,
            'tenant_id' => 1,
            'api_application_id' => $app->id,
            'product_id' => $product->id,
            'payment_method' => 'card',
            'charge_currency' => 'BRL',
            'charge_amount' => 120.0,
            'cajupay_token' => 'tok_x',
            'checkout_session_id' => 'sess-confirm-1',
        ], now()->addMinutes(30));

        $response = $this->postJson(route('api-checkout.cajupay.confirm-order'), [
            'session_token' => $session->session_token,
            'polling_token' => $pollingToken,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order = Order::query()->where('api_checkout_session_id', $session->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('pending', $order->status);
        $this->assertSame('cajupay', $order->gateway);
        $this->assertSame('sess-confirm-1', $order->gateway_id);
    }

    public function test_payment_methods_builder_resolves_card_redundancy(): void
    {
        $stripe = GatewayCredential::create([
            'tenant_id' => 1,
            'gateway_slug' => 'stripe',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $stripe->setEncryptedCredentials(['publishable_key' => 'pk_test', 'secret_key' => 'sk_test']);
        $stripe->save();

        $this->createCajupayCredential(1);

        $methods = \App\Support\CheckoutPaymentMethodsBuilder::build(1, [
            'card' => 'stripe',
            'card_redundancy' => ['cajupay'],
        ]);

        $this->assertCount(1, $methods);
        $this->assertSame('stripe', $methods[0]['gateway_slug']);
    }

    private function createCajupayCredential(int $tenantId): void
    {
        $cred = GatewayCredential::create([
            'tenant_id' => $tenantId,
            'gateway_slug' => 'cajupay',
            'credentials' => '',
            'is_connected' => true,
        ]);
        $cred->setEncryptedCredentials([
            'public_key' => 'pk_test',
            'secret_key' => 'sk_test',
        ]);
        $cred->save();
    }
}
