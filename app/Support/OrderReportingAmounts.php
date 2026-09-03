<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Setting;

/**
 * Valores em BRL (centavos) para integrações que exigem moeda brasileira (ex.: Utmify).
 *
 * Pedidos internacionais podem ter orders.currency em qualquer ISO 4217 (USD, EUR, MZN, JPY…)
 * com liquidação em BRL no metadata (webhook CajuPay). O valor de cobrança na moeda estrangeira
 * nunca deve ir direto para a Utmify — sempre convertido para BRL.
 */
class OrderReportingAmounts
{
    /**
     * Total do pedido em centavos BRL para relatórios externos.
     */
    public static function totalCentsBrl(Order $order): int
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $settlementCents = $meta['settlement_amount_cents'] ?? null;
        $settlementCurrency = MoneyMinorUnits::normalizeCurrencyCode((string) ($meta['settlement_currency'] ?? ''));
        if (is_numeric($settlementCents) && (int) $settlementCents > 0 && $settlementCurrency === 'BRL') {
            return (int) $settlementCents;
        }

        $totalAmount = $order->lineItemsTotalAmount();
        $currency = self::chargeCurrencyForReporting($order);

        if ($currency === 'BRL') {
            return (int) round($totalAmount * 100);
        }

        $fx = $meta['fx_rate'] ?? null;
        if ($fx !== null && $fx !== '' && is_numeric($fx) && (float) $fx > 0) {
            return (int) round($totalAmount * (float) $fx * 100);
        }

        if (isset($meta['amount_brl']) && is_numeric($meta['amount_brl'])) {
            $amountBrl = (float) $meta['amount_brl'];
            if ($amountBrl > 0) {
                return (int) round($amountBrl * 100);
            }
        }

        $displayCurrency = strtoupper(trim((string) ($meta['display_currency'] ?? '')));
        if ($displayCurrency === 'BRL' && isset($meta['display_amount']) && is_numeric($meta['display_amount'])) {
            $display = (float) $meta['display_amount'];
            if ($display > 0) {
                return (int) round($display * 100);
            }
        }

        $convertedBrl = self::estimateAmountBrl($totalAmount, $currency, $order->tenant_id);
        if ($convertedBrl !== null && $convertedBrl > 0) {
            return (int) round($convertedBrl * 100);
        }

        return (int) round($totalAmount * 100);
    }

    /**
     * Estima valor em BRL a partir da moeda de cobrança (taxas do tenant / fallback global).
     */
    public static function estimateAmountBrl(float $amount, string $currency, ?int $tenantId): ?float
    {
        $code = strtoupper(trim($currency));
        if ($code === '' || $code === 'BRL') {
            return $amount > 0 ? round($amount, 2) : null;
        }
        if ($amount <= 0) {
            return null;
        }

        return CheckoutCurrencyCatalog::brlFromForeignAmount(
            $amount,
            $code,
            self::tenantCurrenciesFor($tenantId)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function tenantCurrenciesFor(?int $tenantId): array
    {
        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');

        $list = is_array($currencies) ? $currencies : (array) config('products.currencies');

        return CheckoutCurrencyCatalog::currenciesForCheckout($list);
    }

    /**
     * Moeda efetiva da cobrança (qualquer ISO suportada, não só USD).
     */
    private static function chargeCurrencyForReporting(Order $order): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $paymentCurrency = strtoupper(trim((string) ($meta['payment_currency'] ?? '')));
        if ($paymentCurrency !== '' && CheckoutCurrencyCatalog::isSupported($paymentCurrency)) {
            return $paymentCurrency;
        }

        return $order->getCurrencyOrDefault();
    }

    /**
     * Rateio proporcional de uma linha (produto / bump) no total em centavos BRL.
     */
    public static function lineCentsBrl(Order $order, float $lineAmount): int
    {
        $totalCents = self::totalCentsBrl($order);
        $linesTotal = $order->lineItemsTotalAmount();

        if ($linesTotal <= 0 || $lineAmount <= 0) {
            return $totalCents;
        }

        return max(1, (int) round($totalCents * ($lineAmount / $linesTotal)));
    }

    /**
     * Rateio proporcional de várias linhas que soma exatamente totalCentsBrl (último item absorve o resto).
     *
     * @param  array<int, float>  $lineAmounts
     * @return array<int, int>
     */
    public static function allocateLineCentsBrl(Order $order, array $lineAmounts): array
    {
        $count = count($lineAmounts);
        if ($count === 0) {
            return [];
        }

        $totalCents = self::totalCentsBrl($order);
        $linesTotal = round(array_sum($lineAmounts), 2);

        if ($linesTotal <= 0) {
            $out = array_fill(0, $count, 0);
            $out[0] = $totalCents;

            return $out;
        }

        $allocated = [];
        $sum = 0;
        foreach ($lineAmounts as $i => $lineAmount) {
            $amount = (float) $lineAmount;
            if ($i === $count - 1) {
                $allocated[] = max(0, $totalCents - $sum);
                continue;
            }
            if ($amount <= 0) {
                $allocated[] = 0;
                continue;
            }
            $cents = max(1, (int) round($totalCents * ($amount / $linesTotal)));
            $allocated[] = $cents;
            $sum += $cents;
        }

        return $allocated;
    }
}
