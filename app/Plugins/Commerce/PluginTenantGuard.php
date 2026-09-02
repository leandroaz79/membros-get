<?php

namespace App\Plugins\Commerce;

use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Exceptions\HttpResponseException;

class PluginTenantGuard
{
    public static function assertTenantId(int $tenantId): void
    {
        if ($tenantId <= 0) {
            abort(422, 'tenant_id inválido.');
        }
    }

    public static function assertProductBelongsToTenant(Product $product, int $tenantId): void
    {
        self::assertTenantId($tenantId);
        if ((int) $product->tenant_id !== $tenantId) {
            abort(403, 'Produto não pertence a este tenant.');
        }
    }

    public static function assertOfferBelongsToTenant(?ProductOffer $offer, int $tenantId): void
    {
        if ($offer === null) {
            return;
        }
        $offer->loadMissing('product');
        if (! $offer->product) {
            abort(404, 'Oferta inválida.');
        }
        self::assertProductBelongsToTenant($offer->product, $tenantId);
    }

    public static function assertPlanBelongsToTenant(?SubscriptionPlan $plan, int $tenantId): void
    {
        if ($plan === null) {
            return;
        }
        $plan->loadMissing('product');
        if (! $plan->product) {
            abort(404, 'Plano inválido.');
        }
        self::assertProductBelongsToTenant($plan->product, $tenantId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function jsonForbidden(string $message = 'Acesso negado.'): HttpResponseException
    {
        throw new HttpResponseException(response()->json(['message' => $message], 403));
    }
}
