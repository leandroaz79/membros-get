<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

class ProductSmsConfigTest extends TestCase
{
    public function test_product_update_persists_sms_checkout_config(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $product = $this->createTestProduct([
            'name' => 'Produto SMS',
            'slug' => 'produto-sms',
        ]);

        $sms = [
            'access_delivery' => [
                'enabled' => true,
                'body_text' => 'Acesso {link_acesso}',
            ],
            'pix_generated' => [
                'enabled' => false,
                'body_text' => 'PIX {link_pix}',
            ],
            'cart_recovery' => [
                'enabled' => true,
                'deadline_hours' => 24,
                'stages' => [
                    ['delay_minutes' => 15, 'body_text' => 'Volte {link_checkout}'],
                ],
            ],
        ];

        $response = $this->actingAs($user)->put("/produtos/{$product->id}", [
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => '',
            'type' => Product::TYPE_LINK,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 49.9,
            'currency' => 'BRL',
            'is_active' => true,
            'sms' => $sms,
        ]);

        $response->assertRedirect();

        $product->refresh();
        $stored = $product->checkout_config['sms'] ?? [];

        $this->assertTrue($stored['access_delivery']['enabled'] ?? false);
        $this->assertSame('Acesso {link_acesso}', $stored['access_delivery']['body_text'] ?? null);
        $this->assertSame(24, $stored['cart_recovery']['deadline_hours'] ?? null);
        $this->assertSame(15, $stored['cart_recovery']['stages'][0]['delay_minutes'] ?? null);
    }
}
