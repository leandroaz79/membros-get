<?php

namespace App\Plugins\Commerce;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;

class PluginCommercePricing
{
    /**
     * Preço unitário efetivo em BRL para catálogo/carrinho (sem cupom).
     */
    public static function unitAmountBrl(
        Product $product,
        ?ProductOffer $offer = null,
        ?SubscriptionPlan $plan = null,
    ): float {
        if ($plan !== null) {
            return (float) ($plan->price ?? $product->price ?? 0);
        }
        if ($offer !== null) {
            return (float) ($offer->price ?? $product->price ?? 0);
        }

        return (float) ($product->price ?? 0);
    }

    public static function currency(
        Product $product,
        ?ProductOffer $offer = null,
        ?SubscriptionPlan $plan = null,
    ): string {
        if ($plan !== null) {
            return strtoupper((string) ($plan->getCurrencyOrDefault() ?? 'BRL'));
        }
        if ($offer !== null) {
            return strtoupper((string) ($offer->getCurrencyOrDefault() ?? 'BRL'));
        }

        return strtoupper((string) ($product->currency ?? 'BRL'));
    }
}
