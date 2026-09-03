<?php

namespace App\Services\Commerce;

use App\Events\CheckoutBeforeProcess;
use App\Models\CommerceCart;
use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Plugins\Commerce\PluginCommercePricing;
use App\Plugins\Commerce\PluginTenantGuard;
use App\Plugins\PluginCheckoutExtensionRegistry;
use App\Plugins\PluginHookBus;
use Illuminate\Support\Str;

class CommerceCheckoutBridge
{
    /**
     * @param  array<string, mixed>  $customer  email (required), name?, cpf?, phone?
     * @return array{checkout_url: string, order_id: int, session_token: string}
     */
    public function startFromCart(CommerceCart $cart, array $customer): array
    {
        $cart->loadMissing(['lines.product']);
        if ($cart->isExpired()) {
            abort(410, 'Carrinho expirado.');
        }
        if ($cart->lines->isEmpty()) {
            abort(422, 'Carrinho vazio.');
        }

        $tenantId = (int) $cart->tenant_id;
        PluginTenantGuard::assertTenantId($tenantId);

        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'E-mail do cliente obrigatório.');
        }

        $currency = null;
        $total = 0.0;
        $anchorProduct = null;
        $lineSnapshots = [];

        foreach ($cart->lines as $line) {
            $product = Product::forTenant($tenantId)->where('id', $line->product_id)->where('is_active', true)->first();
            if (! $product) {
                abort(422, 'Produto indisponível no carrinho.');
            }
            $offer = $line->product_offer_id
                ? ProductOffer::where('id', $line->product_offer_id)->where('product_id', $product->id)->first()
                : null;
            $plan = $line->subscription_plan_id
                ? SubscriptionPlan::where('id', $line->subscription_plan_id)->where('product_id', $product->id)->first()
                : null;
            $lineCurrency = PluginCommercePricing::currency($product, $offer, $plan);
            if ($currency === null) {
                $currency = $lineCurrency;
            } elseif ($lineCurrency !== $currency) {
                abort(422, 'Itens com moedas diferentes não podem ser pagos juntos.');
            }
            $lineTotal = (float) $line->unit_amount * (int) $line->quantity;
            $total += $lineTotal;
            if ($anchorProduct === null) {
                $anchorProduct = $product;
            }
            $lineSnapshots[] = [
                'product_id' => $product->id,
                'product_offer_id' => $offer?->id,
                'subscription_plan_id' => $plan?->id,
                'quantity' => (int) $line->quantity,
                'unit_amount' => (float) $line->unit_amount,
                'amount' => $lineTotal,
                'name' => $product->name,
            ];
        }

        $name = trim((string) ($customer['name'] ?? '')) ?: $email;
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => bcrypt(Str::random(32)),
                'role' => User::ROLE_ALUNO,
                'tenant_id' => $tenantId,
            ]
        );

        $cartValidated = [
            'email' => $email,
            'commerce_cart_id' => $cart->id,
        ];
        $pluginCheckoutData = PluginCheckoutExtensionRegistry::decodeCheckoutDataFromRequest(request());
        $before = new CheckoutBeforeProcess($anchorProduct, $cartValidated, $cart, $pluginCheckoutData);
        PluginCheckoutExtensionRegistry::invokeProcessHandlers($anchorProduct, $cartValidated, $pluginCheckoutData, $before);
        PluginHookBus::doAction('checkout.before_process', $before, $anchorProduct, $cartValidated, $pluginCheckoutData);
        event($before);
        if ($before->abort !== null && $before->abort !== '') {
            abort(422, $before->abort);
        }

        $firstLine = $cart->lines->first();
        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $firstLine->product_id,
            'product_offer_id' => $firstLine->product_offer_id,
            'subscription_plan_id' => $firstLine->subscription_plan_id,
            'amount' => round($total, 2),
            'currency' => $currency ?? 'BRL',
            'email' => $email,
            'cpf' => $customer['cpf'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'status' => 'pending',
            'metadata' => [
                'commerce_cart_id' => $cart->id,
                'commerce_multi_line' => true,
                'customer_name' => $name,
            ],
        ]);

        $pos = 0;
        foreach ($cart->lines as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line->product_id,
                'product_offer_id' => $line->product_offer_id,
                'subscription_plan_id' => $line->subscription_plan_id,
                'amount' => round((float) $line->unit_amount * (int) $line->quantity, 2),
                'position' => $pos++,
            ]);
        }

        $token = Str::random(48);
        $expires = now()->addHours((int) config('plugins.commerce_checkout_ttl_hours', 2));
        CommerceCheckoutSession::create([
            'tenant_id' => $tenantId,
            'commerce_cart_id' => $cart->id,
            'session_token' => $token,
            'order_id' => $order->id,
            'amount' => $order->amount,
            'currency' => $currency ?? 'BRL',
            'customer' => [
                'email' => $email,
                'name' => $name,
                'cpf' => $customer['cpf'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            'line_items' => $lineSnapshots,
            'metadata' => ['source' => 'commerce_cart'],
            'expires_at' => $expires,
        ]);

        return [
            'checkout_url' => url('/commerce/checkout/'.$token),
            'order_id' => (int) $order->id,
            'session_token' => $token,
        ];
    }
}
