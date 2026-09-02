<?php

namespace App\Services;

use App\Models\StorefrontDomain;
use App\Plugins\Commerce\PluginTenantGuard;

class StorefrontDomainService
{
    public static function register(int $tenantId, string $host, ?string $pluginSlug = null, bool $verified = false): StorefrontDomain
    {
        PluginTenantGuard::assertTenantId($tenantId);
        $normalized = StorefrontDomain::normalizeHost($host);
        if ($normalized === null) {
            abort(422, 'Host inválido.');
        }

        return StorefrontDomain::updateOrCreate(
            ['host' => $normalized],
            [
                'tenant_id' => $tenantId,
                'plugin_slug' => $pluginSlug !== null && $pluginSlug !== '' ? $pluginSlug : null,
                'is_verified' => $verified,
            ]
        );
    }

    public static function verify(int $tenantId, string $host): bool
    {
        $normalized = StorefrontDomain::normalizeHost($host);
        if ($normalized === null) {
            return false;
        }
        $domain = StorefrontDomain::where('host', $normalized)->where('tenant_id', $tenantId)->first();
        if (! $domain) {
            return false;
        }
        $domain->update(['is_verified' => true]);

        return true;
    }
}
