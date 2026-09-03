<?php

namespace App\Plugins\Commerce;

use App\Events\CommerceCheckoutBuilding;
use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

class PluginCommerceCheckoutStarter
{
    /**
     * Inicia checkout commerce para um plugin (loja customizada).
     *
     * @param  array{email: string, name?: string, cpf?: string, phone?: string}  $customer
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array{name: string, quantity?: int, amount: float, product_id?: string}>  $lineItems
     * @return array{checkout_url: string, order_id: int, session_token: string}
     */
    public function start(
        int $tenantId,
        string $pluginSlug,
        array $customer,
        float $amount,
        string $currency = 'BRL',
        array $metadata = [],
        array $lineItems = [],
    ): array {
        PluginTenantGuard::assertTenantId($tenantId);

        $email = trim((string) ($customer['email'] ?? ''));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'E-mail do cliente obrigatório.');
        }

        $amount = round(max(0, $amount), 2);
        if ($amount <= 0) {
            abort(422, 'Valor do pedido inválido.');
        }

        $pluginSlug = trim($pluginSlug);
        if ($pluginSlug === '') {
            abort(422, 'Plugin slug obrigatório.');
        }

        $metadata = array_merge($metadata, [
            'plugin_checkout' => $pluginSlug,
            'customer_name' => trim((string) ($customer['name'] ?? '')) ?: $email,
        ]);

        $building = new CommerceCheckoutBuilding($tenantId, $pluginSlug, [
            'customer' => $customer,
            'amount' => $amount,
            'currency' => $currency,
            'metadata' => $metadata,
            'line_items' => $lineItems,
        ]);
        event($building);
        if ($building->abort !== null && $building->abort !== '') {
            abort(422, $building->abort);
        }

        $shell = app(CheckoutShellService::class)->productForTenant($tenantId);
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

        $order = Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $shell->id,
            'amount' => $amount,
            'currency' => $currency,
            'email' => $email,
            'cpf' => $customer['cpf'] ?? null,
            'phone' => $customer['phone'] ?? null,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        $sessionLineItems = $lineItems !== [] ? $lineItems : [[
            'name' => 'Pedido',
            'quantity' => 1,
            'amount' => $amount,
        ]];

        $token = Str::random(48);
        $expires = now()->addHours((int) config('plugins.commerce_checkout_ttl_hours', 2));

        CommerceCheckoutSession::create([
            'tenant_id' => $tenantId,
            'commerce_cart_id' => null,
            'session_token' => $token,
            'order_id' => $order->id,
            'amount' => $amount,
            'currency' => $currency,
            'customer' => [
                'email' => $email,
                'name' => $name,
                'cpf' => $customer['cpf'] ?? null,
                'phone' => $customer['phone'] ?? null,
            ],
            'line_items' => $sessionLineItems,
            'metadata' => [
                'source' => 'plugin_commerce',
                'plugin_checkout' => $pluginSlug,
            ],
            'expires_at' => $expires,
        ]);

        return [
            'checkout_url' => url('/commerce/checkout/'.$token),
            'order_id' => (int) $order->id,
            'session_token' => $token,
        ];
    }
}
