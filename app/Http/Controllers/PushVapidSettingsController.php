<?php

namespace App\Http\Controllers;

use App\Support\VapidKeysManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushVapidSettingsController extends Controller
{
    public function generate(Request $request, VapidKeysManager $manager): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        $force = (bool) ($validated['force'] ?? false);
        $result = $force
            ? $manager->generate(true)
            : $manager->ensureConfigured(false);

        if (! ($result['success'] ?? false)) {
            $status = match ($result['error'] ?? '') {
                'env_missing', 'env_not_writable', 'write_failed' => 422,
                default => 500,
            };

            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Falha ao gerar chaves VAPID.',
            ], $status);
        }

        return response()->json([
            'success' => true,
            'configured' => (bool) ($result['configured'] ?? false),
            'already_configured' => (bool) ($result['already_configured'] ?? false),
            'public_key' => $result['public_key'] ?? null,
            'message' => $result['message'] ?? 'Chaves VAPID configuradas.',
        ]);
    }
}
