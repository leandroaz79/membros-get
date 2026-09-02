<?php

namespace App\Support;

class MoneyMinorUnits
{
    /** ISO 4217 currencies without fractional units (amount_cents = major units). Alinhado a Stripe + config. */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
        'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public static function normalizeCurrencyCode(string $code): string
    {
        $normalized = strtoupper(trim($code));

        return $normalized !== '' ? $normalized : 'BRL';
    }

    public static function isZeroDecimal(string $currency): bool
    {
        $code = self::normalizeCurrencyCode($currency);
        $fromConfig = config('checkout_currencies.zero_decimal', []);

        return in_array($code, self::ZERO_DECIMAL, true)
            || (is_array($fromConfig) && in_array($code, $fromConfig, true));
    }

    public static function toMinorUnits(float $amount, string $currency): int
    {
        $code = self::normalizeCurrencyCode($currency);
        if (self::isZeroDecimal($code)) {
            return max(1, (int) round($amount));
        }

        return max(1, (int) round($amount * 100));
    }

    public static function fromMinorUnits(int $minor, string $currency): float
    {
        $code = self::normalizeCurrencyCode($currency);
        if (self::isZeroDecimal($code)) {
            return (float) $minor;
        }

        return round($minor / 100, 2);
    }
}
