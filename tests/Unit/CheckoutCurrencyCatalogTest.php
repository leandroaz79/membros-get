<?php

namespace Tests\Unit;

use App\Services\ExchangeRateService;
use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CheckoutCurrencyCatalogTest extends TestCase
{
    public function test_currency_for_country_mapping(): void
    {
        $this->assertSame('BRL', CheckoutCurrencyCatalog::currencyForCountry('BR'));
        $this->assertSame('EUR', CheckoutCurrencyCatalog::currencyForCountry('DE'));
        $this->assertSame('MXN', CheckoutCurrencyCatalog::currencyForCountry('MX'));
        $this->assertSame('GBP', CheckoutCurrencyCatalog::currencyForCountry('GB'));
    }

    public function test_unknown_country_falls_back_to_usd(): void
    {
        $this->assertSame('USD', CheckoutCurrencyCatalog::currencyForCountry('ZZ'));
    }

    public function test_brl_from_foreign_amount_uses_rate_to_brl(): void
    {
        $tenant = [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.2],
            ['code' => 'EUR', 'rate_to_brl' => 0.16],
        ];

        $this->assertSame(50.0, CheckoutCurrencyCatalog::brlFromForeignAmount(10.0, 'USD', $tenant));
        $this->assertSame(62.5, CheckoutCurrencyCatalog::brlFromForeignAmount(10.0, 'EUR', $tenant));
        $this->assertSame(10.0, CheckoutCurrencyCatalog::brlFromForeignAmount(10.0, 'BRL', $tenant));
    }

    public function test_foreign_from_brl_amount_uses_rate_to_brl(): void
    {
        $tenant = [
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'USD', 'rate_to_brl' => 0.2],
        ];

        $this->assertSame(20.0, CheckoutCurrencyCatalog::foreignFromBrlAmount(100.0, 'USD', $tenant));
    }

    #[DataProvider('featuredOrderProvider')]
    public function test_merge_tenant_currencies_puts_featured_first(string $firstCode): void
    {
        $merged = CheckoutCurrencyCatalog::mergeTenantCurrencies([
            ['code' => 'MXN', 'symbol' => '$', 'label' => 'Peso', 'rate_to_brl' => 3.5],
            ['code' => 'BRL', 'symbol' => 'R$', 'label' => 'Real', 'rate_to_brl' => 1],
            ['code' => 'USD', 'symbol' => '$', 'label' => 'Dólar', 'rate_to_brl' => 0.18],
        ]);

        $this->assertSame($firstCode, $merged[0]['code']);
    }

    public static function featuredOrderProvider(): array
    {
        return [['BRL']];
    }

    public function test_is_supported_for_catalog_codes(): void
    {
        $this->assertTrue(CheckoutCurrencyCatalog::isSupported('MXN'));
        $this->assertFalse(CheckoutCurrencyCatalog::isSupported('HRK'));
    }

    public function test_is_legacy_currency_list_detects_default_three(): void
    {
        $legacy = config('products.currencies');
        $this->assertTrue(CheckoutCurrencyCatalog::isLegacyCurrencyList($legacy));
        $this->assertFalse(CheckoutCurrencyCatalog::isLegacyCurrencyList([
            ['code' => 'BRL', 'rate_to_brl' => 1],
            ['code' => 'MXN', 'rate_to_brl' => 3.5],
        ]));
    }

    public function test_currencies_for_checkout_expands_legacy_and_resolves_mxn_rate(): void
    {
        Cache::forget(ExchangeRateService::CACHE_KEY_RATES_FROM_BRL);
        Http::fake([
            'open.er-api.com/*' => Http::response([
                'result' => 'success',
                'base_code' => 'BRL',
                'rates' => [
                    'MXN' => 3.45,
                    'USD' => 0.18,
                    'EUR' => 0.16,
                    'GBP' => 0.14,
                    'COP' => 730.0,
                    'ARS' => 275.0,
                ],
            ], 200),
            'api.frankfurter.app/*' => Http::response([
                'base' => 'BRL',
                'rates' => ['MXN' => 3.45, 'USD' => 0.18, 'EUR' => 0.16, 'GBP' => 0.14],
            ], 200),
        ]);

        $rows = CheckoutCurrencyCatalog::currenciesForCheckout((array) config('products.currencies'));
        $codes = array_column($rows, 'code');

        $this->assertContains('MXN', $codes);
        $this->assertGreaterThan(3, count($rows));

        $mxn = collect($rows)->firstWhere('code', 'MXN');
        $this->assertNotNull($mxn);
        $this->assertGreaterThan(0, $mxn['rate_to_brl']);

        $converted = CheckoutCurrencyCatalog::foreignFromBrlAmount(100.0, 'MXN', $rows);
        $this->assertGreaterThan(100, $converted);

        $cop = collect($rows)->firstWhere('code', 'COP');
        $this->assertNotNull($cop);
        $this->assertGreaterThan(0, $cop['rate_to_brl']);
        $this->assertGreaterThan(100, CheckoutCurrencyCatalog::foreignFromBrlAmount(100.0, 'COP', $rows));
    }
}
