<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessEmailService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccessLinkForOrderTest extends TestCase
{
    public function test_get_access_link_for_order_uses_signed_magic_link_for_member_area(): void
    {
        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'magic'.substr(uniqid('', true), -6),
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'is_renewal' => false,
        ]);

        $link = app(AccessEmailService::class)->getAccessLinkForOrder($order);

        $this->assertStringContainsString('signature=', $link);
        $this->assertStringContainsString('expires=', $link);
        $this->assertStringContainsString('u='.$user->id, $link);
        $this->assertStringContainsString('/access', $link);
    }

    public function test_get_access_link_matches_email_link_for_same_order(): void
    {
        Mail::fake();

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'same'.substr(uniqid('', true), -6),
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'metadata' => ['access_password_temp' => encrypt('SenhaTeste123')],
            'is_renewal' => false,
        ]);
        $order->load(['product', 'user']);

        $service = app(AccessEmailService::class);
        $fromHelper = $service->getAccessLinkForOrder($order);

        $service->sendForOrder($order, true);

        Mail::assertSent(\App\Mail\AccessGrantedMail::class, function (\App\Mail\AccessGrantedMail $mail) use ($fromHelper) {
            $decoded = html_entity_decode($mail->htmlBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString($fromHelper, $decoded);

            return true;
        });
    }

    public function test_thank_you_page_redirect_url_uses_magic_link(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['tenant_id' => 1]);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => 'ty'.substr(uniqid('', true), -6),
        ]);
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => $user->email,
            'is_renewal' => false,
        ]);

        $this->get(route('checkout.thank-you', ['order_id' => $order->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/ThankYou')
                ->where('redirect_url', fn ($url) => is_string($url)
                    && str_contains($url, '/m/'.$product->checkout_slug.'/access')
                    && str_contains($url, 'signature=')
                    && str_contains($url, 'expires=')
                    && str_contains($url, 'u='.$user->id))
            );
    }
}
