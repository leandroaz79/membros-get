<?php

namespace App\Support;

use App\Services\ExchangeRateService;

class CheckoutCurrencyCatalog
{
    /** @var array<string, array{symbol: string, label: string, zero_decimal: bool}>|null */
    private static ?array $metadataCache = null;

    /**
     * @return list<string>
     */
    public static function featuredCodes(): array
    {
        return array_values(config('checkout_currencies.featured', ['BRL', 'USD', 'EUR']));
    }

    /**
     * @return list<string>
     */
    public static function supportedCodes(): array
    {
        $codes = config('checkout_currencies.supported', []);

        return is_array($codes) ? array_values(array_unique(array_map('strtoupper', $codes))) : [];
    }

    public static function isSupported(string $code): bool
    {
        $code = strtoupper(trim($code));

        return $code !== '' && in_array($code, self::supportedCodes(), true);
    }

    /**
     * @return array{symbol: string, label: string, zero_decimal: bool}
     */
    public static function metadataFor(string $code): array
    {
        $code = strtoupper(trim($code));
        if (self::$metadataCache === null) {
            self::$metadataCache = [];
            $symbols = config('checkout_currencies.symbols', []);
            $labels = config('checkout_currencies.labels', []);
            $zeroDecimal = config('checkout_currencies.zero_decimal', []);
            foreach (self::supportedCodes() as $c) {
                self::$metadataCache[$c] = [
                    'symbol' => is_array($symbols) && isset($symbols[$c]) ? (string) $symbols[$c] : $c,
                    'label' => is_array($labels) && isset($labels[$c]) ? (string) $labels[$c] : $c,
                    'zero_decimal' => is_array($zeroDecimal) && in_array($c, $zeroDecimal, true),
                ];
            }
        }

        return self::$metadataCache[$code] ?? [
            'symbol' => $code,
            'label' => $code,
            'zero_decimal' => MoneyMinorUnits::isZeroDecimal($code),
        ];
    }

    public static function currencyForCountry(string $countryCode): string
    {
        $country = strtoupper(trim($countryCode));
        if ($country === 'BR') {
            return 'BRL';
        }

        $map = config('checkout_currencies.country_to_currency', []);
        if (is_array($map) && isset($map[$country])) {
            $cur = strtoupper((string) $map[$country]);
            if (self::isSupported($cur)) {
                return $cur;
            }
        }

        $eurCountries = config('checkout_currencies.eur_countries', []);
        if (is_array($eurCountries) && in_array($country, $eurCountries, true)) {
            return 'EUR';
        }

        return 'USD';
    }

    /**
     * Converte valor na moeda estrangeira para BRL usando rate_to_brl do tenant.
     *
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function brlFromForeignAmount(float $amount, string $currency, array $tenantCurrencies): float
    {
        $code = strtoupper(trim($currency));
        if ($code === '' || $code === 'BRL') {
            return round($amount, 2);
        }

        $rate = CheckoutCustomPriceByCurrency::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            $rate = self::fallbackRateToBrl($code);
        }
        if ($rate <= 0) {
            return round($amount, 2);
        }

        return round($amount / $rate, 2);
    }

    /**
     * Converte BRL para moeda estrangeira (exibição).
     *
     * @param  array<int, array{code?: string, rate_to_brl?: float|int|string}>  $tenantCurrencies
     */
    public static function foreignFromBrlAmount(float $amountBrl, string $currency, array $tenantCurrencies): float
    {
        $code = strtoupper(trim($currency));
        if ($code === '' || $code === 'BRL') {
            return round($amountBrl, 2);
        }

        $rate = CheckoutCustomPriceByCurrency::rateToBrlForCode($tenantCurrencies, $code);
        if ($rate <= 0) {
            $rate = self::fallbackRateToBrl($code);
        }
        if ($rate <= 0) {
            return round($amountBrl, 2);
        }

        $converted = $amountBrl * $rate;

        return MoneyMinorUnits::isZeroDecimal($code)
            ? (float) max(1, (int) round($converted))
            : round($converted, 2);
    }

    public static function fallbackRateToBrl(string $code): float
    {
        $code = strtoupper(trim($code));
        if ($code === 'BRL') {
            return 1.0;
        }

        $defaults = config('products.rates', []);
        if ($code === 'USD') {
            return (float) ($defaults['brl_usd'] ?? 0.18);
        }
        if ($code === 'EUR') {
            return (float) ($defaults['brl_eur'] ?? 0.16);
        }

        try {
            $cached = app(ExchangeRateService::class)->getCachedRatesMap();

            return isset($cached[$code]) && $cached[$code] > 0 ? (float) $cached[$code] : 0.0;
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Lista do tenant ainda só com BRL/USD/EUR (install antigo ou sem importar catálogo).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function isLegacyCurrencyList(array $rows): bool
    {
        $codes = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes === [] || (count($codes) <= 3 && array_diff($codes, ['BRL', 'EUR', 'USD']) === []);
    }

    /**
     * Moedas para checkout: catálogo completo com taxas (cache Frankfurter) quando o tenant ainda não importou.
     *
     * @param  array<int, array<string, mixed>>  $tenantRows
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function currenciesForCheckout(array $tenantRows): array
    {
        $tenantRows = is_array($tenantRows) ? $tenantRows : [];

        if ($tenantRows === [] || self::isLegacyCurrencyList($tenantRows)) {
            $byCode = [];
            foreach (self::defaultTenantCurrencyRows() as $row) {
                $byCode[$row['code']] = $row;
            }
            foreach ($tenantRows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
                if ($code === '' || ! isset($byCode[$code])) {
                    continue;
                }
                if ((float) ($row['rate_to_brl'] ?? 0) > 0) {
                    $byCode[$code]['rate_to_brl'] = (float) $row['rate_to_brl'];
                }
                if (! empty($row['symbol'])) {
                    $byCode[$code]['symbol'] = (string) $row['symbol'];
                }
                if (! empty($row['label'])) {
                    $byCode[$code]['label'] = (string) $row['label'];
                }
            }
            $merged = array_values($byCode);
        } else {
            $merged = self::mergeTenantCurrencies($tenantRows);
        }

        return self::ensureRatesResolved($merged);
    }

    /**
     * Garante taxas preenchidas (cache + API) antes de enviar ao checkout ou salvar settings.
     *
     * @param  list<array{code: string, symbol?: string, label?: string, rate_to_brl?: float}>  $rows
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function ensureRatesResolved(array $rows): array
    {
        $rows = self::applyResolvedRates($rows);
        $missingCodes = [];
        foreach ($rows as $row) {
            $code = strtoupper((string) ($row['code'] ?? ''));
            if ($code !== '' && $code !== 'BRL' && (float) ($row['rate_to_brl'] ?? 0) <= 0) {
                $missingCodes[] = $code;
            }
        }
        if ($missingCodes !== []) {
            try {
                $rows = app(ExchangeRateService::class)->applyRatesToCurrencyRows($rows, $missingCodes);
            } catch (\Throwable) {
                // mantém o que applyResolvedRates já preencheu
            }
        }

        return $rows;
    }

    /**
     * Preenche rate_to_brl ausente com cache/API e fallbacks USD/EUR.
     *
     * @param  list<array{code: string, symbol?: string, label?: string, rate_to_brl?: float}>  $rows
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function applyResolvedRates(array $rows): array
    {
        $cached = [];
        try {
            $cached = app(ExchangeRateService::class)->getCachedRatesMap();
        } catch (\Throwable) {
            $cached = [];
        }

        $resolved = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['code'])) {
                continue;
            }
            $code = strtoupper(trim((string) $row['code']));
            $meta = self::metadataFor($code);
            $tenantRate = $code === 'BRL' ? 1.0 : max(0.0, (float) ($row['rate_to_brl'] ?? 0));
            $rate = $tenantRate;
            if (isset($cached[$code]) && $cached[$code] > 0 && ($tenantRate <= 0 || $tenantRate === 0.0)) {
                $rate = (float) $cached[$code];
            }
            if ($rate <= 0) {
                $rate = self::fallbackRateToBrl($code);
            }
            $resolved[] = [
                'code' => $code,
                'symbol' => (string) ($row['symbol'] ?? $meta['symbol']),
                'label' => (string) ($row['label'] ?? $meta['label']),
                'rate_to_brl' => $rate > 0 ? $rate : ($code === 'BRL' ? 1.0 : 0.0),
            ];
        }

        return self::mergeTenantCurrencies($resolved);
    }

    /**
     * @param  array<int, array<string, mixed>>  $tenantRows
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function mergeTenantCurrencies(array $tenantRows): array
    {
        $byCode = [];
        foreach ($tenantRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($code === '') {
                continue;
            }
            $meta = self::metadataFor($code);
            $byCode[$code] = [
                'code' => $code,
                'symbol' => (string) ($row['symbol'] ?? $meta['symbol']),
                'label' => (string) ($row['label'] ?? $meta['label']),
                'rate_to_brl' => max(0.0, (float) ($row['rate_to_brl'] ?? 0)),
            ];
        }

        if (! isset($byCode['BRL'])) {
            $meta = self::metadataFor('BRL');
            $byCode['BRL'] = [
                'code' => 'BRL',
                'symbol' => $meta['symbol'],
                'label' => $meta['label'],
                'rate_to_brl' => 1.0,
            ];
        }

        $featured = self::featuredCodes();
        $ordered = [];
        foreach ($featured as $code) {
            if (isset($byCode[$code])) {
                $ordered[] = $byCode[$code];
                unset($byCode[$code]);
            }
        }
        $rest = array_values($byCode);
        usort($rest, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return array_merge($ordered, $rest);
    }

    /**
     * @return array<string, array{symbol: string, label: string}>
     */
    public static function presetsMap(): array
    {
        $map = [];
        foreach (self::supportedCodes() as $code) {
            $meta = self::metadataFor($code);
            $map[$code] = [
                'symbol' => $meta['symbol'],
                'label' => $meta['label'],
            ];
        }

        return $map;
    }

    /**
     * Lista padrão para novos tenants: todas as moedas suportadas com taxas do config legado onde existir.
     *
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public static function defaultTenantCurrencyRows(): array
    {
        $rates = config('products.rates', []);
        $rows = [];
        foreach (self::supportedCodes() as $code) {
            $meta = self::metadataFor($code);
            $rate = 1.0;
            if ($code === 'BRL') {
                $rate = 1.0;
            } elseif ($code === 'USD') {
                $rate = (float) ($rates['brl_usd'] ?? 0.18);
            } elseif ($code === 'EUR') {
                $rate = (float) ($rates['brl_eur'] ?? 0.16);
            } else {
                $rate = 0.0;
            }
            $rows[] = [
                'code' => $code,
                'symbol' => $meta['symbol'],
                'label' => $meta['label'],
                'rate_to_brl' => $rate,
            ];
        }

        return self::mergeTenantCurrencies($rows);
    }

    /**
     * @return array<string, float>
     */
    public static function ratesMapFromTenant(array $tenantCurrencies): array
    {
        $map = [];
        foreach ($tenantCurrencies as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = isset($row['code']) ? strtoupper(trim((string) $row['code'])) : '';
            if ($code !== '') {
                $map[$code] = max(0.0, (float) ($row['rate_to_brl'] ?? 0));
            }
        }

        return $map;
    }
}
