<?php

namespace App\Plugins\Commerce;

use App\Events\StorefrontLoading;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class PluginCommerceCatalog
{
    /**
     * @param  array<string, mixed>  $filters  q, type, billing_type, limit, offset
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public static function listProducts(int $tenantId, array $filters = [], ?Request $request = null): array
    {
        PluginTenantGuard::assertTenantId($tenantId);
        if ($request !== null) {
            event(new StorefrontLoading($tenantId, $request));
        }

        $limit = min(100, max(1, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $query = Product::forTenant($tenantId)->where('is_active', true);

        if (! empty($filters['q']) && is_string($filters['q'])) {
            $q = trim($filters['q']);
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', '%'.$q.'%')
                    ->orWhere('checkout_slug', 'like', '%'.$q.'%');
            });
        }
        if (! empty($filters['type'])) {
            $query->where('type', (string) $filters['type']);
        }
        if (! empty($filters['billing_type'])) {
            $query->where('billing_type', (string) $filters['billing_type']);
        }

        $total = (clone $query)->count();
        $products = $query->orderBy('name')->offset($offset)->limit($limit)->get();

        return [
            'items' => $products->map(fn (Product $p) => self::productDto($p))->values()->all(),
            'total' => $total,
        ];
    }

    public static function getProduct(int $tenantId, string $idOrSlug): ?array
    {
        PluginTenantGuard::assertTenantId($tenantId);
        $product = Product::forTenant($tenantId)
            ->where('is_active', true)
            ->where(function ($q) use ($idOrSlug) {
                $q->where('id', $idOrSlug)->orWhere('checkout_slug', $idOrSlug);
            })
            ->first();

        return $product ? self::productDto($product, detailed: true) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listOffers(int $tenantId, string $productId): array
    {
        PluginTenantGuard::assertTenantId($tenantId);
        $product = Product::forTenant($tenantId)->where('id', $productId)->where('is_active', true)->first();
        if (! $product) {
            return [];
        }

        return ProductOffer::where('product_id', $product->id)
            ->orderBy('position')
            ->get()
            ->map(fn (ProductOffer $o) => self::offerDto($o, $product))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listPlans(int $tenantId, string $productId): array
    {
        PluginTenantGuard::assertTenantId($tenantId);
        $product = Product::forTenant($tenantId)->where('id', $productId)->where('is_active', true)->first();
        if (! $product) {
            return [];
        }

        return SubscriptionPlan::where('product_id', $product->id)
            ->orderBy('position')
            ->get()
            ->map(fn (SubscriptionPlan $pl) => self::planDto($pl, $product))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function productDto(Product $product, bool $detailed = false): array
    {
        $dto = [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $detailed ? ($product->description ?? '') : null,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'price' => (float) ($product->price ?? 0),
            'currency' => strtoupper((string) ($product->currency ?? 'BRL')),
            'checkout_slug' => (string) ($product->checkout_slug ?? ''),
            'checkout_url' => url('/c/'.($product->checkout_slug ?? '')),
            'image_url' => $product->image ? (str_starts_with((string) $product->image, 'http') ? $product->image : url('/storage/'.$product->image)) : null,
        ];
        if ($detailed) {
            $dto['offers'] = self::listOffers((int) $product->tenant_id, $product->id);
            $dto['plans'] = self::listPlans((int) $product->tenant_id, $product->id);
        }

        return array_filter($dto, fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function offerDto(ProductOffer $offer, Product $product): array
    {
        return [
            'id' => $offer->id,
            'public_id' => $offer->public_id ?? null,
            'product_id' => $product->id,
            'name' => $offer->name,
            'price' => PluginCommercePricing::unitAmountBrl($product, $offer),
            'currency' => PluginCommercePricing::currency($product, $offer),
            'checkout_slug' => $offer->checkout_slug ?: $product->checkout_slug,
            'checkout_url' => url('/c/'.($offer->checkout_slug ?: $product->checkout_slug)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function planDto(SubscriptionPlan $plan, Product $product): array
    {
        return [
            'id' => $plan->id,
            'public_id' => $plan->public_id ?? null,
            'product_id' => $product->id,
            'name' => $plan->name,
            'price' => PluginCommercePricing::unitAmountBrl($product, null, $plan),
            'currency' => PluginCommercePricing::currency($product, null, $plan),
            'checkout_slug' => $plan->checkout_slug ?: $product->checkout_slug,
            'checkout_url' => url('/c/'.($plan->checkout_slug ?: $product->checkout_slug)),
        ];
    }
}
