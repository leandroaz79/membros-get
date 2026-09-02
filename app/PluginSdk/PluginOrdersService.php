<?php

namespace App\PluginSdk;

use App\Models\Order;
use App\Plugins\PluginOrderContext;

/**
 * Pedidos e contexto normalizado para listeners de plugins.
 */
class PluginOrdersService
{
    public function findForTenant(int $tenantId, int|string $orderId): ?Order
    {
        return Order::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($orderId)
            ->first();
    }

    public function context(Order $order): PluginOrderContext
    {
        return PluginOrderContext::fromOrder($order);
    }
}
