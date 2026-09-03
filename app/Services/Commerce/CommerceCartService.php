<?php

namespace App\Services\Commerce;

use App\Events\Commerce\CartLineAdded;
use App\Events\Commerce\CartUpdated;
use App\Models\CommerceCart;
use App\Models\CommerceCartLine;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Plugins\Commerce\PluginCommercePricing;
use App\Plugins\Commerce\PluginTenantGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommerceCartService
{
    public const COOKIE_NAME = 'getfy_commerce_cart';

    public function getOrCreate(Request $request, int $tenantId): CommerceCart
    {
        PluginTenantGuard::assertTenantId($tenantId);
        $token = $request->cookie(self::COOKIE_NAME);
        if (is_string($token) && $token !== '') {
            $cart = CommerceCart::where('session_token', $token)
                ->where('tenant_id', $tenantId)
                ->with('lines')
                ->first();
            if ($cart && ! $cart->isExpired()) {
                return $cart;
            }
        }

        $ttlDays = (int) config('plugins.commerce_cart_ttl_days', 14);
        $cart = CommerceCart::create([
            'tenant_id' => $tenantId,
            'session_token' => Str::random(48),
            'expires_at' => now()->addDays(max(1, $ttlDays)),
        ]);

        return $cart->load('lines');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addLine(CommerceCart $cart, array $payload): CommerceCartLine
    {
        $this->assertCartActive($cart);
        $maxLines = (int) config('plugins.commerce_cart_max_lines', 50);
        if ($cart->lines()->count() >= $maxLines) {
            abort(422, 'Carrinho cheio.');
        }

        $product = Product::forTenant($cart->tenant_id)
            ->where('id', $payload['product_id'] ?? '')
            ->where('is_active', true)
            ->firstOrFail();

        $offer = null;
        if (! empty($payload['product_offer_id'])) {
            $offer = ProductOffer::where('id', (int) $payload['product_offer_id'])
                ->where('product_id', $product->id)
                ->firstOrFail();
        }

        $plan = null;
        if (! empty($payload['subscription_plan_id'])) {
            $plan = SubscriptionPlan::where('id', (int) $payload['subscription_plan_id'])
                ->where('product_id', $product->id)
                ->firstOrFail();
        }

        $qty = max(1, min(99, (int) ($payload['quantity'] ?? 1)));
        $unit = PluginCommercePricing::unitAmountBrl($product, $offer, $plan);

        $line = CommerceCartLine::create([
            'commerce_cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_offer_id' => $offer?->id,
            'subscription_plan_id' => $plan?->id,
            'quantity' => $qty,
            'unit_amount' => $unit,
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            'position' => (int) $cart->lines()->max('position') + 1,
        ]);

        event(new CartLineAdded($cart, $line));
        event(new CartUpdated($cart->fresh('lines')));

        return $line;
    }

    public function updateQuantity(CommerceCart $cart, int $lineId, int $quantity): CommerceCartLine
    {
        $this->assertCartActive($cart);
        $line = $cart->lines()->where('id', $lineId)->firstOrFail();
        $line->update(['quantity' => max(1, min(99, $quantity))]);
        event(new CartUpdated($cart->fresh('lines')));

        return $line;
    }

    public function removeLine(CommerceCart $cart, int $lineId): void
    {
        $this->assertCartActive($cart);
        $cart->lines()->where('id', $lineId)->delete();
        event(new CartUpdated($cart->fresh('lines')));
    }

    public function clear(CommerceCart $cart): void
    {
        $cart->lines()->delete();
        event(new CartUpdated($cart->fresh('lines')));
    }

    /**
     * @return array{subtotal: float, currency: string, line_count: int}
     */
    public function totals(CommerceCart $cart): array
    {
        $cart->loadMissing('lines.product');
        $currency = 'BRL';
        $subtotal = 0.0;
        foreach ($cart->lines as $line) {
            $product = $line->product;
            if ($product) {
                $offer = $line->product_offer_id
                    ? ProductOffer::find($line->product_offer_id)
                    : null;
                $plan = $line->subscription_plan_id
                    ? SubscriptionPlan::find($line->subscription_plan_id)
                    : null;
                $currency = PluginCommercePricing::currency($product, $offer, $plan);
            }
            $subtotal += (float) $line->unit_amount * (int) $line->quantity;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'currency' => $currency,
            'line_count' => $cart->lines->count(),
        ];
    }

    public function cartCookie(CommerceCart $cart): \Symfony\Component\HttpFoundation\Cookie
    {
        $ttlDays = (int) config('plugins.commerce_cart_ttl_days', 14);

        return cookie(
            self::COOKIE_NAME,
            $cart->session_token,
            max(1, $ttlDays) * 24 * 60,
            '/',
            null,
            request()->isSecure(),
            true,
            false,
            'lax'
        );
    }

    private function assertCartActive(CommerceCart $cart): void
    {
        if ($cart->isExpired()) {
            abort(410, 'Carrinho expirado.');
        }
    }
}
