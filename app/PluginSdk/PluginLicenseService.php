<?php

namespace App\PluginSdk;

use App\Services\PluginLicenseClient;

/**
 * Licença / entitlement local para plugins da loja getfy.org.
 */
class PluginLicenseService
{
    public function __construct(
        private readonly ?PluginLicenseClient $client = null,
    ) {}

    private function client(): PluginLicenseClient
    {
        return $this->client ?? app(PluginLicenseClient::class);
    }

    /**
     * @return array{valid: bool, reason?: string, entitlement?: array<string, mixed>|null}
     */
    public function check(string $slug): array
    {
        $plugin = \App\Models\Plugin::query()->find($slug);
        if (! $plugin) {
            return ['valid' => false, 'reason' => 'plugin_not_installed'];
        }

        $config = is_array($plugin->config) ? $plugin->config : [];
        $license = is_array($config['license'] ?? null) ? $config['license'] : [];
        $token = trim((string) ($license['purchase_token'] ?? ''));
        if ($token === '') {
            return ['valid' => true, 'entitlement' => null];
        }

        $failClosed = (bool) config('plugins.license_fail_closed', false);
        $cacheKey = 'getfy.plugin_license.'.$slug.'.'.hash('sha256', $token);
        $ttl = (int) config('plugins.license_cache_seconds', 3600);

        try {
            $cached = \Illuminate\Support\Facades\Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('valid', $cached)) {
                return $cached;
            }
            $result = $this->client()->verify($slug, $token, (string) ($plugin->version ?? ''));
            \Illuminate\Support\Facades\Cache::put($cacheKey, $result, max(60, $ttl));

            return $result;
        } catch (\Throwable $e) {
            report($e);
            if ($failClosed) {
                return ['valid' => false, 'reason' => 'license_unreachable'];
            }

            return ['valid' => true, 'reason' => 'license_unreachable_fail_open', 'entitlement' => $license];
        }
    }

    public function assertValid(string $slug): void
    {
        $result = $this->check($slug);
        if (! ($result['valid'] ?? false)) {
            throw new \RuntimeException('Licença do plugin "'.$slug.'" inválida: '.($result['reason'] ?? 'unknown'));
        }
    }

    public function storePurchaseToken(string $slug, string $purchaseToken, ?string $checksum = null): void
    {
        $plugin = \App\Models\Plugin::query()->findOrFail($slug);
        $config = is_array($plugin->config) ? $plugin->config : [];
        $config['license'] = array_filter([
            'purchase_token' => $purchaseToken,
            'checksum' => $checksum,
            'stored_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '');
        $plugin->config = $config;
        $plugin->save();
        \Illuminate\Support\Facades\Cache::forget('getfy.plugin_license.'.$slug.'.'.hash('sha256', $purchaseToken));
    }
}
