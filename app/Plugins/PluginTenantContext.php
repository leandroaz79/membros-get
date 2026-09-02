<?php

namespace App\Plugins;

use App\Services\StorefrontTenantResolver;
use Illuminate\Http\Request;

/**
 * Resolve tenant_id em rotas públicas de plugins (API, vitrine, webhooks).
 */
class PluginTenantContext
{
    public static function fromRequest(Request $request): ?int
    {
        $fromAttr = $request->attributes->get('storefront_tenant_id');
        if (is_numeric($fromAttr) && (int) $fromAttr > 0) {
            return (int) $fromAttr;
        }

        $fromHost = StorefrontTenantResolver::tenantIdFromHost($request);
        if ($fromHost !== null && $fromHost > 0) {
            return $fromHost;
        }

        $header = $request->header('X-Tenant-Id');
        if (is_numeric($header)) {
            return (int) $header;
        }

        $query = $request->query('tenant_id');
        if (is_numeric($query)) {
            return (int) $query;
        }

        $user = $request->user();
        if ($user && isset($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        return null;
    }

    public static function requireFromRequest(Request $request): int
    {
        $id = self::fromRequest($request);
        if ($id === null || $id <= 0) {
            abort(422, 'tenant_id obrigatório (header X-Tenant-Id ou query tenant_id).');
        }

        return $id;
    }
}
