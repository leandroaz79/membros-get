<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Setting;
use App\Models\SubscriptionPlan;

/**
 * Alinha o valor cobrado em sessões de checkout hospedado (API) ao cálculo de pedidos na API de pagamentos.
 */
class ApiHostedCheckoutPricing
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function tenantCurrencies(int $tenantId): array
    {
        $raw = Setting::get('currencies', null, $tenantId);
        $list = $raw
            ? (is_string($raw) ? json_decode($raw, true) : $raw)
            : config('products.currencies');

        return CheckoutCurrencyCatalog::currenciesForCheckout(is_array($list) ? $list : []);
    }

    public static function expectedAmountBrl(
        int $tenantId,
        string $productId,
        ?int $productOfferId,
        ?int $subscriptionPlanId,
    ): ?float {
        $product = Product::where('id', $productId)->where('tenant_id', $tenantId)->first();
        if (! $product) {
            return null;
        }

        $orderAmount = (float) $product->price;
        $currency = $product->currency ?? 'BRL';

        if ($productOfferId) {
            $offer = ProductOffer::where('id', $productOfferId)->where('product_id', $product->id)->first();
            if (! $offer) {
                return null;
            }
            $orderAmount = (float) $offer->price;
            $currency = $offer->getCurrencyOrDefault();
        } elseif ($subscriptionPlanId) {
            $plan = SubscriptionPlan::where('id', $subscriptionPlanId)->where('product_id', $product->id)->first();
            if (! $plan) {
                return null;
            }
            $orderAmount = (float) $plan->price;
            $currency = $plan->getCurrencyOrDefault();
        }

        if ($currency !== 'BRL') {
            $orderAmount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $orderAmount,
                $currency,
                self::tenantCurrencies($tenantId)
            );
        }

        return $orderAmount;
    }

    public static function amountToBrl(float $amount, string $currency, ?int $tenantId = null): float
    {
        $currency = strtoupper($currency);
        if ($currency === 'BRL') {
            return $amount;
        }

        $list = $tenantId !== null ? self::tenantCurrencies($tenantId) : CheckoutCurrencyCatalog::currenciesForCheckout(
            (array) config('products.currencies')
        );

        return CheckoutCurrencyCatalog::brlFromForeignAmount($amount, $currency, $list);
    }
}
