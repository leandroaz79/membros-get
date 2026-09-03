<?php

namespace App\Http\Controllers\Commerce;

use App\Http\Controllers\Controller;
use App\Plugins\PluginTenantContext;
use App\Services\Commerce\CommerceCartService;
use App\Services\Commerce\CommerceCheckoutBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommerceCartController extends Controller
{
    public function __construct(
        protected CommerceCartService $carts,
        protected CommerceCheckoutBridge $checkoutBridge,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $cart->load('lines.product');

        return response()->json([
            'cart' => $this->cartPayload($cart),
            'totals' => $this->carts->totals($cart),
        ])->cookie($this->carts->cartCookie($cart));
    }

    public function addLine(Request $request): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $validated = $request->validate([
            'product_id' => ['required', 'string'],
            'product_offer_id' => ['nullable', 'integer'],
            'subscription_plan_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'metadata' => ['nullable', 'array'],
        ]);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $this->carts->addLine($cart, $validated);
        $cart = $cart->fresh('lines.product');

        return response()->json([
            'cart' => $this->cartPayload($cart),
            'totals' => $this->carts->totals($cart),
        ])->cookie($this->carts->cartCookie($cart));
    }

    public function updateLine(Request $request, int $lineId): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ]);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $this->carts->updateQuantity($cart, $lineId, (int) $validated['quantity']);
        $cart = $cart->fresh('lines.product');

        return response()->json([
            'cart' => $this->cartPayload($cart),
            'totals' => $this->carts->totals($cart),
        ])->cookie($this->carts->cartCookie($cart));
    }

    public function removeLine(Request $request, int $lineId): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $this->carts->removeLine($cart, $lineId);
        $cart = $cart->fresh('lines.product');

        return response()->json([
            'cart' => $this->cartPayload($cart),
            'totals' => $this->carts->totals($cart),
        ])->cookie($this->carts->cartCookie($cart));
    }

    public function clear(Request $request): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $this->carts->clear($cart);
        $cart = $cart->fresh('lines');

        return response()->json([
            'cart' => $this->cartPayload($cart),
            'totals' => $this->carts->totals($cart),
        ])->cookie($this->carts->cartCookie($cart));
    }

    public function startCheckout(Request $request): JsonResponse
    {
        $tenantId = PluginTenantContext::requireFromRequest($request);
        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
        ]);
        $cart = $this->carts->getOrCreate($request, $tenantId);
        $result = $this->checkoutBridge->startFromCart($cart, $validated['customer']);

        return response()->json($result)->cookie($this->carts->cartCookie($cart));
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPayload(\App\Models\CommerceCart $cart): array
    {
        return [
            'id' => $cart->id,
            'tenant_id' => $cart->tenant_id,
            'lines' => $cart->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'product_offer_id' => $line->product_offer_id,
                'subscription_plan_id' => $line->subscription_plan_id,
                'quantity' => (int) $line->quantity,
                'unit_amount' => (float) $line->unit_amount,
                'metadata' => $line->metadata,
            ])->values()->all(),
        ];
    }
}
