<?php

namespace App\Services;

use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    private const FRANKFURTER_URL = 'https://api.frankfurter.app/latest';

    /** API gratuita com taxas completas a partir de BRL (Frankfurter cobre só ~30 pares). */
    private const ER_API_URL = 'https://open.er-api.com/v6/latest/BRL';

    private const CHUNK_SIZE = 30;

    private const MIN_CACHE_ENTRIES = 50;

    public const CACHE_KEY_RATES_FROM_BRL = 'checkout.frankfurter_rates_from_brl';

    public const CACHE_TTL_HOURS = 24;

    /**
     * Taxas BRL → moeda estrangeira (rate_to_brl: unidades da moeda por 1 BRL).
     *
     * @param  list<string>  $codes  Códigos ISO 4217 (sem BRL)
     * @return array<string, float>
     */
    public function fetchRatesFromBrl(array $codes): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($c) => strtoupper(trim((string) $c)),
            $codes
        ), fn ($c) => $c !== '' && $c !== 'BRL')));

        if ($codes === []) {
            return [];
        }

        $fullMap = $this->getCachedRatesMap();
        $out = [];
        $missing = [];
        foreach ($codes as $code) {
            if (isset($fullMap[$code]) && $fullMap[$code] > 0) {
                $out[$code] = $fullMap[$code];
            } else {
                $missing[] = $code;
            }
        }

        if ($missing !== []) {
            foreach ($this->fetchRatesFromBrlFrankfurter($missing) as $code => $rate) {
                $out[$code] = $rate;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, float>
     */
    private function fetchRatesFromBrlFrankfurter(array $codes): array
    {
        $out = [];
        foreach (array_chunk($codes, self::CHUNK_SIZE) as $chunk) {
            $part = $this->fetchFrankfurterChunk($chunk);
            foreach ($part as $code => $rate) {
                $out[$code] = $rate;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, float>
     */
    private function fetchFrankfurterChunk(array $codes): array
    {
        $to = implode(',', $codes);
        try {
            $response = Http::timeout(15)->get(self::FRANKFURTER_URL, [
                'from' => 'BRL',
                'to' => $to,
            ]);
            if (! $response->successful()) {
                Log::warning('ExchangeRateService: Frankfurter HTTP error', [
                    'status' => $response->status(),
                    'codes' => $codes,
                ]);

                return [];
            }
            $data = $response->json();
            $rates = is_array($data['rates'] ?? null) ? $data['rates'] : [];
            $out = [];
            foreach ($codes as $code) {
                if (isset($rates[$code]) && is_numeric($rates[$code])) {
                    $out[$code] = (float) $rates[$code];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService: Frankfurter exception', [
                'message' => $e->getMessage(),
                'codes' => $codes,
            ]);

            return [];
        }
    }

    /**
     * Mapa completo: moeda => unidades por 1 BRL (open.er-api.com).
     *
     * @return array<string, float>
     */
    public function fetchAllRatesFromBrlErApi(): array
    {
        try {
            $response = Http::timeout(25)->get(self::ER_API_URL);
            if (! $response->successful()) {
                Log::warning('ExchangeRateService: open.er-api HTTP error', [
                    'status' => $response->status(),
                ]);

                return [];
            }
            $data = $response->json();
            if (($data['result'] ?? '') !== 'success' || ! is_array($data['rates'] ?? null)) {
                return [];
            }
            $supported = array_flip(CheckoutCurrencyCatalog::supportedCodes());
            $out = [];
            foreach ($data['rates'] as $code => $rate) {
                $code = strtoupper((string) $code);
                if ($code === 'BRL' || ! isset($supported[$code]) || ! is_numeric($rate)) {
                    continue;
                }
                $f = (float) $rate;
                if ($f > 0) {
                    $out[$code] = $f;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('ExchangeRateService: open.er-api exception', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Mescla taxas em linhas de moeda do tenant (preenche zeros).
     *
     * @param  list<array{code: string, symbol?: string, label?: string, rate_to_brl?: float}>  $existing
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public function applyRatesToCurrencyRows(array $existing, ?array $codesToFetch = null): array
    {
        $byCode = [];
        foreach ($existing as $row) {
            if (! is_array($row) || empty($row['code'])) {
                continue;
            }
            $code = strtoupper(trim((string) $row['code']));
            $meta = CheckoutCurrencyCatalog::metadataFor($code);
            $byCode[$code] = [
                'code' => $code,
                'symbol' => (string) ($row['symbol'] ?? $meta['symbol']),
                'label' => (string) ($row['label'] ?? $meta['label']),
                'rate_to_brl' => (float) ($row['rate_to_brl'] ?? 0),
            ];
        }

        $toFetch = $codesToFetch ?? array_keys($byCode);
        $toFetch = array_values(array_filter($toFetch, fn ($c) => strtoupper($c) !== 'BRL'));
        $fetched = $this->fetchRatesFromBrl($toFetch);

        foreach ($fetched as $code => $rate) {
            if (! isset($byCode[$code])) {
                $meta = CheckoutCurrencyCatalog::metadataFor($code);
                $byCode[$code] = [
                    'code' => $code,
                    'symbol' => $meta['symbol'],
                    'label' => $meta['label'],
                    'rate_to_brl' => $rate,
                ];
            } else {
                $byCode[$code]['rate_to_brl'] = $rate;
            }
        }

        foreach ($byCode as $code => &$row) {
            if ($code === 'BRL') {
                $row['rate_to_brl'] = 1.0;

                continue;
            }
            if ($row['rate_to_brl'] <= 0) {
                $fallback = CheckoutCurrencyCatalog::fallbackRateToBrl($code);
                if ($fallback > 0) {
                    $row['rate_to_brl'] = $fallback;
                }
            }
        }
        unset($row);

        if (! isset($byCode['BRL'])) {
            $meta = CheckoutCurrencyCatalog::metadataFor('BRL');
            $byCode['BRL'] = [
                'code' => 'BRL',
                'symbol' => $meta['symbol'],
                'label' => $meta['label'],
                'rate_to_brl' => 1.0,
            ];
        }

        return CheckoutCurrencyCatalog::mergeTenantCurrencies(array_values($byCode));
    }

    /**
     * Importa todas as moedas suportadas do catálogo com taxas da API (onde disponível).
     *
     * @return list<array{code: string, symbol: string, label: string, rate_to_brl: float}>
     */
    public function buildFullCatalogWithRates(): array
    {
        $rows = CheckoutCurrencyCatalog::defaultTenantCurrencyRows();
        $codes = array_values(array_filter(
            array_map(fn ($r) => $r['code'], $rows),
            fn ($c) => $c !== 'BRL'
        ));

        return $this->applyRatesToCurrencyRows($rows, $codes);
    }

    /**
     * Taxas BRL → moeda (rate_to_brl), em cache 24h.
     *
     * @return array<string, float>
     */
    public function getCachedRatesMap(): array
    {
        $cached = Cache::get(self::CACHE_KEY_RATES_FROM_BRL);
        if (is_array($cached) && count($cached) >= self::MIN_CACHE_ENTRIES) {
            return $cached;
        }

        $map = $this->buildRatesMap();

        if (count($map) >= self::MIN_CACHE_ENTRIES) {
            Cache::put(self::CACHE_KEY_RATES_FROM_BRL, $map, now()->addHours(self::CACHE_TTL_HOURS));
        }

        return $map;
    }

    /**
     * @return array<string, float>
     */
    private function buildRatesMap(): array
    {
        $map = $this->fetchAllRatesFromBrlErApi();

        if (count($map) < self::MIN_CACHE_ENTRIES) {
            $partial = $this->fetchRatesFromBrlFrankfurter(CheckoutCurrencyCatalog::supportedCodes());
            foreach ($partial as $code => $rate) {
                if (! isset($map[$code]) || $map[$code] <= 0) {
                    $map[$code] = $rate;
                }
            }
        }

        $defaults = config('products.rates', []);
        if (! isset($map['USD']) || $map['USD'] <= 0) {
            $map['USD'] = (float) ($defaults['brl_usd'] ?? 0.18);
        }
        if (! isset($map['EUR']) || $map['EUR'] <= 0) {
            $map['EUR'] = (float) ($defaults['brl_eur'] ?? 0.16);
        }

        return $map;
    }

    public function forgetCachedRates(): void
    {
        Cache::forget(self::CACHE_KEY_RATES_FROM_BRL);
    }
}
