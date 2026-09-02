<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Plugins\Commerce\PluginCommerceCatalog;
use App\Plugins\PluginTenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommerceCatalogController extends Controller
{
    public function products(Request $request): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $filters = $request->only(['q', 'type', 'billing_type', 'limit', 'offset']);

        return response()->json(
            PluginCommerceCatalog::listProducts($tenantId, $filters, $request)
        );
    }

    public function product(Request $request, string $idOrSlug): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $product = PluginCommerceCatalog::getProduct($tenantId, $idOrSlug);
        if ($product === null) {
            abort(404);
        }

        return response()->json(['product' => $product]);
    }
}
