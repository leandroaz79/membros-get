<?php

use App\Models\Setting;
use App\Services\ExchangeRateService;
use App\Support\CheckoutCurrencyCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $exchange = app(ExchangeRateService::class);

        $tenantIds = Setting::query()
            ->where('key', 'currencies')
            ->pluck('tenant_id')
            ->unique();

        if ($tenantIds->isEmpty()) {
            try {
                $rows = $exchange->buildFullCatalogWithRates();
                Setting::set('currencies', $rows, null);
            } catch (\Throwable $e) {
                Log::warning('Migration expand currencies: falha ao popular global', [
                    'message' => $e->getMessage(),
                ]);
                Setting::set('currencies', CheckoutCurrencyCatalog::defaultTenantCurrencyRows(), null);
            }

            return;
        }

        foreach ($tenantIds as $tenantId) {
            $raw = Setting::query()
                ->where('key', 'currencies')
                ->where('tenant_id', $tenantId)
                ->value('value');
            $existing = $raw ? json_decode((string) $raw, true) : null;
            if (! is_array($existing) || count($existing) <= 3) {
                try {
                    $rows = $exchange->buildFullCatalogWithRates();
                } catch (\Throwable $e) {
                    $rows = CheckoutCurrencyCatalog::defaultTenantCurrencyRows();
                }
            } else {
                $merged = CheckoutCurrencyCatalog::mergeTenantCurrencies($existing);
                try {
                    $rows = $exchange->applyRatesToCurrencyRows($merged);
                } catch (\Throwable $e) {
                    $rows = $merged;
                }
            }
            Setting::set('currencies', $rows, $tenantId);
        }
    }

    public function down(): void
    {
        // Não reverte — tenants podem ter editado moedas manualmente.
    }
};
