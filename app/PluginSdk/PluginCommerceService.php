<?php

namespace App\PluginSdk;

use App\Plugins\Commerce\PluginCommerceCatalog;
use App\Plugins\Commerce\PluginCommerceCheckoutStarter;
use Illuminate\Http\Request;

/**
 * Commerce API interna para plugins (catálogo, checkout).
 */
class PluginCommerceService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function catalog(int $tenantId, array $filters = [], ?Request $request = null): array
    {
        return PluginCommerceCatalog::listProducts($tenantId, $filters, $request);
    }

    public function product(int $tenantId, string $idOrSlug): ?array
    {
        return PluginCommerceCatalog::getProduct($tenantId, $idOrSlug);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, array<string, mixed>>  $lineItems
     * @return array{order_id: int, checkout_url: string, token: string}
     */
    public function startCheckout(
        int $tenantId,
        string $pluginSlug,
        array $customer,
        float $amount,
        array $metadata = [],
        array $lineItems = [],
    ): array {
        return app(PluginCommerceCheckoutStarter::class)->start(
            tenantId: $tenantId,
            pluginSlug: $pluginSlug,
            customer: $customer,
            amount: $amount,
            metadata: $metadata,
            lineItems: $lineItems,
        );
    }
}
