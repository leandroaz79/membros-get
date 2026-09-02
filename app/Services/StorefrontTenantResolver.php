<?php

namespace App\Services;

use App\Models\StorefrontDomain;
use Illuminate\Http\Request;

class StorefrontTenantResolver
{
    public static function tenantIdFromHost(Request $request): ?int
    {
        $host = StorefrontDomain::normalizeHost($request->getHost());
        if ($host === null) {
            return null;
        }

        $domain = StorefrontDomain::where('host', $host)
            ->where('is_verified', true)
            ->first();

        return $domain ? (int) $domain->tenant_id : null;
    }

    /**
     * @return array{tenant_id: int, plugin_slug: string|null}|null
     */
    public static function resolveFromHost(Request $request): ?array
    {
        $host = StorefrontDomain::normalizeHost($request->getHost());
        if ($host === null) {
            return null;
        }

        $domain = StorefrontDomain::where('host', $host)
            ->where('is_verified', true)
            ->first();

        if (! $domain) {
            return null;
        }

        return [
            'tenant_id' => (int) $domain->tenant_id,
            'plugin_slug' => $domain->plugin_slug,
        ];
    }
}
