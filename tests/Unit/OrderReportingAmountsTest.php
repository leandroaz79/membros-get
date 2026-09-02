<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\UtmifyService;
use App\Support\OrderReportingAmounts;
use Tests\TestCase;

class OrderReportingAmountsTest extends TestCase
{
    public function test_total_cents_brl_uses_settlement_from_cajupay_metadata(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 4.86,
            'currency' => 'USD',
            'email' => 'intl@example.com',
            'metadata' => [
                'settlement_amount_cents' => 2700,
                'settlement_currency' => 'BRL',
                'fx_rate' => '5.555555',
            ],
        ]);

        $this->assertSame(2700, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_total_cents_brl_uses_order_amount_when_currency_is_brl(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97.00,
            'currency' => 'BRL',
            'email' => 'br@example.com',
        ]);

        $this->assertSame(9700, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_utmify_payload_uses_settlement_brl_not_usd_charge(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 4.86,
            'currency' => 'USD',
            'email' => 'utmify-intl@example.com',
            'metadata' => [
                'settlement_amount_cents' => 2700,
                'settlement_currency' => 'BRL',
                'checkout_payment_method' => 'google_pay',
            ],
        ]);

        $payload = app(UtmifyService::class)->buildPayload($order, 'paid');

        $this->assertSame(2700, $payload['commission']['totalPriceInCents']);
        $this->assertSame(2700, $payload['products'][0]['priceInCents']);
        $this->assertSame('credit_card', $payload['paymentMethod']);
    }

    public function test_total_cents_brl_converts_foreign_currency_with_tenant_rate_when_no_settlement(): void
    {
        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], null, 1);

        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 5.00,
            'currency' => 'USD',
            'email' => 'usd-no-settlement@example.com',
        ]);

        // 5 USD / 0.18 = 27.78 BRL → 2778 centavos
        $this->assertSame(2778, OrderReportingAmounts::totalCentsBrl($order));
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: float, 3: int}>
     */
    public static function foreignCurrencyConversionProvider(): array
    {
        return [
            'EUR' => ['EUR', 10.0, 0.16, 6250],   // 10 / 0.16 = 62.50 BRL
            'GBP' => ['GBP', 8.0, 0.14, 5714],    // 8 / 0.14 ≈ 57.14 BRL
            'MZN' => ['MZN', 500.0, 12.5, 4000],  // 500 / 12.5 = 40.00 BRL
            'JPY' => ['JPY', 1500.0, 30.0, 5000], // 1500 / 30 = 50.00 BRL
            'ARS' => ['ARS', 5000.0, 250.0, 2000], // 5000 / 250 = 20.00 BRL
        ];
    }

    /**
     * @dataProvider foreignCurrencyConversionProvider
     */
    public function test_total_cents_brl_converts_any_foreign_currency_to_brl(
        string $currency,
        float $amount,
        float $rateToBrl,
        int $expectedCents
    ): void {
        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => $currency, 'rate_to_brl' => $rateToBrl],
        ], null, 1);

        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => $currency,
            'email' => strtolower($currency).'@example.com',
        ]);

        $this->assertSame($expectedCents, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_total_cents_brl_uses_payment_currency_from_metadata_when_present(): void
    {
        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'EUR', 'rate_to_brl' => 0.16],
        ], null, 1);

        $user = User::factory()->create();
        $product = $this->createTestProduct();

        // Pedido ainda com currency USD no registro, mas webhook gravou payment_currency EUR
        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10.0,
            'currency' => 'USD',
            'email' => 'payment-currency@example.com',
            'metadata' => ['payment_currency' => 'EUR'],
        ]);

        $this->assertSame(6250, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_utmify_payload_converts_usd_to_brl_without_settlement_metadata(): void
    {
        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], null, 1);

        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 5.00,
            'currency' => 'USD',
            'email' => 'utmify-usd@example.com',
            'metadata' => ['checkout_payment_method' => 'card'],
        ]);

        $payload = app(UtmifyService::class)->buildPayload($order, 'paid');

        $this->assertSame(2778, $payload['commission']['totalPriceInCents']);
        $this->assertSame(2778, $payload['products'][0]['priceInCents']);
    }

    public function test_total_cents_brl_prefers_amount_brl_metadata_over_tenant_conversion(): void
    {
        Setting::set('currencies', [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.18],
        ], null, 1);

        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'amount' => 5.00,
            'currency' => 'USD',
            'email' => 'amount-brl-meta@example.com',
            'metadata' => ['amount_brl' => 28.50],
        ]);

        $this->assertSame(2850, OrderReportingAmounts::totalCentsBrl($order));
    }

    public function test_utmify_payloads_split_main_and_order_bump_with_individual_commissions(): void
    {
        $user = User::factory()->create();
        $main = $this->createTestProduct(['name' => 'Principal']);
        $bumpProduct = $this->createTestProduct(['name' => 'Order Bump Extra']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 81.90,
            'currency' => 'BRL',
            'email' => 'utmify-bump@example.com',
            'gateway_id' => 'gw-order-123',
            'metadata' => ['checkout_payment_method' => 'pix'],
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 67.00,
            'position' => 0,
        ]);
        $bumpItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 14.90,
            'position' => 1,
        ]);

        $payloads = app(UtmifyService::class)->buildPayloads($order->fresh(), 'paid');

        $this->assertCount(2, $payloads);

        $this->assertSame('gw-order-123', $payloads[0]['orderId']);
        $this->assertSame(6700, $payloads[0]['commission']['totalPriceInCents']);
        $this->assertSame(6700, $payloads[0]['products'][0]['priceInCents']);
        $this->assertSame((string) $main->id, $payloads[0]['products'][0]['id']);
        $this->assertCount(1, $payloads[0]['products']);

        $this->assertSame('gw-order-123-ob-'.$bumpItem->id, $payloads[1]['orderId']);
        $this->assertSame(1490, $payloads[1]['commission']['totalPriceInCents']);
        $this->assertSame(1490, $payloads[1]['products'][0]['priceInCents']);
        $this->assertSame((string) $bumpProduct->id, $payloads[1]['products'][0]['id']);
        $this->assertCount(1, $payloads[1]['products']);

        $this->assertSame(
            8190,
            $payloads[0]['commission']['totalPriceInCents'] + $payloads[1]['commission']['totalPriceInCents']
        );
    }

    public function test_utmify_payloads_filter_by_product_keeps_line_amount_not_funnel_total(): void
    {
        $user = User::factory()->create();
        $main = $this->createTestProduct(['name' => 'Principal filtro']);
        $bumpProduct = $this->createTestProduct(['name' => 'Bump filtro']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 81.90,
            'currency' => 'BRL',
            'email' => 'utmify-filter@example.com',
            'gateway_id' => 'gw-filter-1',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 67.00,
            'position' => 0,
        ]);
        $bumpItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 14.90,
            'position' => 1,
        ]);

        $payloads = app(UtmifyService::class)->buildPayloads(
            $order->fresh(),
            'paid',
            [],
            [(string) $bumpProduct->id]
        );

        $this->assertCount(1, $payloads);
        $this->assertSame('gw-filter-1-ob-'.$bumpItem->id, $payloads[0]['orderId']);
        $this->assertSame(1490, $payloads[0]['commission']['totalPriceInCents']);
        $this->assertSame(1490, $payloads[0]['products'][0]['priceInCents']);
        $this->assertSame((string) $bumpProduct->id, $payloads[0]['products'][0]['id']);
    }

    public function test_utmify_payloads_main_product_filter_includes_order_bumps(): void
    {
        $user = User::factory()->create();
        $main = $this->createTestProduct(['name' => 'Checkout principal']);
        $bumpProduct = $this->createTestProduct(['name' => 'Bump do checkout']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 81.90,
            'currency' => 'BRL',
            'email' => 'utmify-main-filter@example.com',
            'gateway_id' => 'gw-main-filter',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 67.00,
            'position' => 0,
        ]);
        $bumpItem = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $bumpProduct->id,
            'amount' => 14.90,
            'position' => 1,
        ]);

        // Integração com apenas o produto principal marcado — bumps do mesmo checkout devem ir juntos.
        $payloads = app(UtmifyService::class)->buildPayloads(
            $order->fresh(),
            'paid',
            [],
            [(string) $main->id]
        );

        $this->assertCount(2, $payloads);
        $this->assertSame('gw-main-filter', $payloads[0]['orderId']);
        $this->assertSame(6700, $payloads[0]['commission']['totalPriceInCents']);
        $this->assertSame('gw-main-filter-ob-'.$bumpItem->id, $payloads[1]['orderId']);
        $this->assertSame(1490, $payloads[1]['commission']['totalPriceInCents']);
        $this->assertSame((string) $bumpProduct->id, $payloads[1]['products'][0]['id']);
    }

    public function test_utmify_payloads_fallback_without_order_items_uses_total(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct(['name' => 'Só principal']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 97.00,
            'currency' => 'BRL',
            'email' => 'utmify-no-items@example.com',
        ]);

        $payloads = app(UtmifyService::class)->buildPayloads($order, 'paid');

        $this->assertCount(1, $payloads);
        $this->assertSame(9700, $payloads[0]['commission']['totalPriceInCents']);
        $this->assertSame(9700, $payloads[0]['products'][0]['priceInCents']);
        $this->assertSame((string) $product->id, $payloads[0]['products'][0]['id']);
    }

    public function test_allocate_line_cents_brl_sums_to_total(): void
    {
        $user = User::factory()->create();
        $product = $this->createTestProduct();

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 100.00,
            'currency' => 'BRL',
            'email' => 'allocate@example.com',
        ]);

        $allocated = OrderReportingAmounts::allocateLineCentsBrl($order, [33.33, 33.33, 33.34]);

        $this->assertSame(10000, array_sum($allocated));
        $this->assertCount(3, $allocated);
    }
}
