<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\InboundWebhookEndpoint;
use App\Services\InboundWebhookFulfillmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InboundCheckoutWebhookController extends Controller
{
    public function handle(Request $request, string $token, InboundWebhookFulfillmentService $fulfillment): JsonResponse
    {
        $endpoint = InboundWebhookEndpoint::query()
            ->where('url_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $endpoint) {
            return response()->json(['success' => false, 'message' => 'Endpoint inválido ou inativo.'], 404);
        }

        $secret = $endpoint->signing_secret;
        if (is_string($secret) && trim($secret) !== '') {
            if (! $this->validSignature($request, trim($secret))) {
                Log::warning('CheckoutExterno: assinatura inválida.', ['endpoint_id' => $endpoint->id]);

                return response()->json(['success' => false, 'message' => 'Assinatura inválida.'], 401);
            }
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['success' => false, 'message' => 'Corpo deve ser JSON.'], 422);
        }

        $result = $fulfillment->fulfill($endpoint, $payload);

        return response()->json($result['json'], $result['status']);
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Webhook-Signature', '');
        $trim = strtolower(trim(str_replace(["\n", "\r"], '', $header)));
        if (preg_match('/^sha256=([a-f0-9]{64})$/', $trim, $m)) {
            $provided = strtolower($m[1]);
        } elseif (preg_match('/^[a-f0-9]{64}$/', $trim)) {
            $provided = $trim;
        } else {
            return false;
        }

        $raw = $request->getContent();
        $computed = strtolower(hash_hmac('sha256', $raw, $secret));

        return hash_equals($computed, $provided);
    }
}
