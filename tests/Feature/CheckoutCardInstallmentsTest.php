<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class CheckoutCardInstallmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_checkout_show_exposes_installments_when_enabled_on_pagarme_product(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 120,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => [
                    'card' => 'pagarme',
                ],
                'card_installments' => [
                    'enabled' => true,
                    'max' => 6,
                ],
            ]),
        ]);

        $response = $this->get('/c/'.$product->checkout_slug);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('card_installments_enabled', true)
            ->where('card_max_installments', 6));
    }

    public function test_api_checkout_show_exposes_installments_from_linked_product(): void
    {
        User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'price' => 120,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'payment_gateways' => [
                    'card' => 'pagarme',
                ],
                'card_installments' => [
                    'enabled' => true,
                    'max' => 4,
                ],
            ]),
        ]);

        $app = ApiApplication::create([
            'tenant_id' => 1,
            'name' => 'App Test',
            'slug' => 'app-test-'.uniqid(),
            'api_key_hash' => password_hash('test-key', PASSWORD_BCRYPT),
            'payment_gateways' => [
                'card' => 'pagarme',
                'pix' => null,
            ],
            'is_active' => true,
        ]);

        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => 1,
            'product_id' => $product->id,
            'session_token' => 'api-sess-'.str_repeat('b', 32),
            'customer' => ['email' => 'buyer@test.com', 'name' => 'Buyer', 'cpf' => '12345678901'],
            'amount' => 120,
            'currency' => 'BRL',
            'expires_at' => now()->addHour(),
        ]);

        $response = $this->get(route('api-checkout.show', ['token' => $session->session_token]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ApiCheckout/Show')
            ->where('card_installments_enabled', true)
            ->where('card_max_installments', 4));
    }
}
