<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\RefundService;
use App\Services\UserProductAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentAreaRefundController extends Controller
{
    public function __construct(
        protected RefundService $refundService,
        protected UserProductAccessService $accessService
    ) {}

    public function eligibility(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (! $this->accessService->userOwnsProduct($user, $product->id)) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            return response()->json([
                'enabled' => false,
                'can_request' => false,
                'message' => 'Reembolso não disponível para este produto.',
            ]);
        }

        return response()->json($this->refundService->eligibility($product, $user));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        if (! $this->accessService->userOwnsProduct($user, $product->id)) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            return response()->json(['message' => 'Reembolso não disponível para este produto.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $refundRequest = $this->refundService->submitRequest($product, $user, $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => $refundRequest->status === 'processing'
                ? 'Solicitação enviada. O reembolso está sendo processado.'
                : 'Solicitação enviada. Aguarde a análise da equipe.',
            'request' => [
                'id' => $refundRequest->id,
                'status' => $refundRequest->status,
                'status_label' => \App\Models\RefundRequest::statusLabel($refundRequest->status),
            ],
        ]);
    }
}
