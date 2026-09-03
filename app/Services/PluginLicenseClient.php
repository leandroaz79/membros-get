<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP para verificação de licença na loja getfy.org.
 */
class PluginLicenseClient
{
    /**
     * @return array{valid: bool, reason?: string, entitlement?: array<string, mixed>|null}
     */
    public function verify(string $slug, string $purchaseToken, string $version = ''): array
    {
        $base = rtrim((string) config('services.plugin_store.url', ''), '/');
        if ($base === '') {
            return ['valid' => true, 'reason' => 'store_not_configured', 'entitlement' => null];
        }

        $url = $base.'/api/v1/plugins/'.$slug.'/verify-license';
        $headers = [];
        $apiKey = config('services.plugin_store.api_key');
        if (is_string($apiKey) && $apiKey !== '') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        $response = Http::timeout(15)
            ->withHeaders($headers)
            ->acceptJson()
            ->post($url, [
                'purchase_token' => $purchaseToken,
                'version' => $version,
                'platform_id' => config('app.name').'-'.md5((string) config('app.key')),
            ]);

        if (! $response->successful()) {
            return [
                'valid' => false,
                'reason' => 'http_'.$response->status(),
                'entitlement' => null,
            ];
        }

        $data = $response->json();
        if (! is_array($data)) {
            return ['valid' => false, 'reason' => 'invalid_response'];
        }

        return [
            'valid' => (bool) ($data['valid'] ?? false),
            'reason' => isset($data['reason']) ? (string) $data['reason'] : null,
            'entitlement' => is_array($data['entitlement'] ?? null) ? $data['entitlement'] : null,
        ];
    }
}
