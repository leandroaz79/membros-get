<?php

namespace App\Http\Controllers;

use App\Models\IntegraxConnection;
use App\Services\IntegraX\IntegraXSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegraxController extends Controller
{
    public function show(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $connection = IntegraxConnection::forTenant($tenantId)->first();

        return response()->json([
            'connection' => $this->connectionToArray($connection),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_token' => ['nullable', 'string', 'max:512'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $connection = IntegraxConnection::forTenant($tenantId)->first() ?? new IntegraxConnection([
            'tenant_id' => $tenantId,
        ]);

        if (array_key_exists('is_active', $validated)) {
            $connection->is_active = (bool) $validated['is_active'];
        }

        if ($request->has('api_token')) {
            $token = trim((string) ($validated['api_token'] ?? ''));
            if ($token !== '') {
                $connection->api_token = $token;
            }
        }

        $connection->save();

        return response()->json([
            'connection' => $this->connectionToArray($connection->fresh()),
        ]);
    }

    public function test(Request $request, IntegraXSmsService $smsService): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'message' => ['nullable', 'string', 'max:160'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $connection = IntegraxConnection::forTenant($tenantId)->first();
        if (! $connection || ! $connection->isConfigured()) {
            return response()->json([
                'success' => false,
                'message' => 'Configure o token da IntegraX antes de testar.',
            ], 422);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        if ($message === '') {
            $message = 'Teste IntegraX via Getfy';
        }

        $result = $smsService->sendNow($connection, $validated['phone'], $message);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionToArray(?IntegraxConnection $connection): array
    {
        if (! $connection) {
            return [
                'configured' => false,
                'is_active' => false,
                'has_token' => false,
                'last_tested_at' => null,
                'last_error' => null,
            ];
        }

        return [
            'configured' => $connection->isConfigured(),
            'is_active' => (bool) $connection->is_active,
            'has_token' => $connection->isConfigured(),
            'api_token' => $connection->isConfigured() ? (string) $connection->api_token : '',
            'last_tested_at' => $connection->last_tested_at?->toISOString(),
            'last_error' => $connection->last_error,
        ];
    }
}
