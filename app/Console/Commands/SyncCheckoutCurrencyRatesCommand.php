<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\ExchangeRateService;
use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Console\Command;

class SyncCheckoutCurrencyRatesCommand extends Command
{
    protected $signature = 'checkout:sync-currency-rates
                            {--tenant= : ID do tenant (omitir = todos com setting currencies)}
                            {--import : Importar catálogo completo de moedas antes de atualizar taxas}';

    protected $description = 'Atualiza rate_to_brl das moedas do checkout via API Frankfurter';

    public function handle(ExchangeRateService $exchangeRateService): int
    {
        $tenantOption = $this->option('tenant');
        $import = (bool) $this->option('import');

        if ($tenantOption !== null && $tenantOption !== '') {
            $tenantId = (int) $tenantOption;
            $this->syncTenant($exchangeRateService, $tenantId, $import);

            return self::SUCCESS;
        }

        $tenantIds = Setting::query()
            ->where('key', 'currencies')
            ->whereNotNull('tenant_id')
            ->pluck('tenant_id')
            ->unique()
            ->filter()
            ->values();

        if ($tenantIds->isEmpty()) {
            $this->syncTenant($exchangeRateService, null, $import);
        } else {
            foreach ($tenantIds as $tid) {
                $this->syncTenant($exchangeRateService, (int) $tid, $import);
            }
        }

        $exchangeRateService->forgetCachedRates();
        $this->info('Taxas de moeda sincronizadas.');

        return self::SUCCESS;
    }

    private function syncTenant(ExchangeRateService $exchangeRateService, ?int $tenantId, bool $import): void
    {
        $raw = Setting::get('currencies', null, $tenantId);
        $existing = $raw
            ? (is_string($raw) ? json_decode($raw, true) : $raw)
            : config('products.currencies');

        if (! is_array($existing)) {
            $existing = config('products.currencies');
        }

        if ($import || count($existing) <= 3) {
            $rows = $exchangeRateService->buildFullCatalogWithRates();
        } else {
            $merged = CheckoutCurrencyCatalog::mergeTenantCurrencies($existing);
            $rows = $exchangeRateService->applyRatesToCurrencyRows($merged);
        }

        Setting::set('currencies', $rows, $tenantId);
        $label = $tenantId === null ? 'global' : "tenant {$tenantId}";
        $this->line("  {$label}: ".count($rows).' moedas');
    }
}
