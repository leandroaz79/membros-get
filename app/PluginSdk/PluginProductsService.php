<?php

namespace App\PluginSdk;

use App\Models\Product;

/**
 * Leitura segura de produtos do tenant (API estável para plugins).
 */
class PluginProductsService
{
    public function findForTenant(int $tenantId, string $idOrSlug): ?Product
    {
        return Product::forTenant($tenantId)
            ->where(function ($q) use ($idOrSlug) {
                $q->where('id', $idOrSlug)->orWhere('checkout_slug', $idOrSlug);
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: array<int, Product>, total: int}
     */
    public function listForTenant(int $tenantId, array $filters = []): array
    {
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $query = Product::forTenant($tenantId);
        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }
        if (! empty($filters['q']) && is_string($filters['q'])) {
            $q = trim($filters['q']);
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', '%'.$q.'%')
                    ->orWhere('checkout_slug', 'like', '%'.$q.'%');
            });
        }

        $total = (clone $query)->count();
        $items = $query->orderBy('name')->offset($offset)->limit($limit)->get();

        return ['items' => $items->all(), 'total' => $total];
    }
}
