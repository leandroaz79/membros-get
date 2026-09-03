<?php

namespace Plugins\GetfyExampleGateway;

use App\Jobs\ProcessPaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook: POST /webhooks/gateways/example-gateway
 *
 * Payload esperado (exemplo):
 * { "transaction_id": "...", "status": "paid", "event": "payment.updated" }
 */
class ExampleGatewayWebhookHandler
{
    public function handle(Request $request, string $slug): JsonResponse
    {
        $gatewayId = (string) ($request->input('transaction_id') ?: $request->input('id') ?: '');
        $statusRaw = strtolower((string) ($request->input('status') ?: 'paid'));
        $event = (string) ($request->input('event') ?: 'payment.updated');

        if ($gatewayId === '') {
            return response()->json(['error' => 'transaction_id required'], 422);
        }

        $mapped = match ($statusRaw) {
            'paid', 'approved', 'completed' => 'paid',
            'cancelled', 'canceled', 'refused', 'refunded' => $statusRaw === 'refunded' ? 'refunded' : 'cancelled',
            default => 'pending',
        };

        try {
            ProcessPaymentWebhook::dispatchSync(
                $slug,
                $gatewayId,
                $event,
                $mapped,
                $request->all()
            );
        } catch (\Throwable $e) {
            Log::warning('ExampleGateway webhook dispatch failed', [
                'slug' => $slug,
                'transaction_id' => $gatewayId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        return response()->json(['ok' => true]);
    }
}
