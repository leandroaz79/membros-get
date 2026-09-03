<?php

namespace App\PluginSdk;

use App\Plugins\PluginTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Resolução de tenant para plugins (painel, API pública, vitrine).
 */
class PluginTenantService
{
    public function current(?Request $request = null): ?int
    {
        $request ??= request();
        if ($request instanceof Request) {
            $fromRequest = PluginTenantContext::fromRequest($request);
            if ($fromRequest !== null && $fromRequest > 0) {
                return $fromRequest;
            }
        }

        $user = Auth::user();
        if ($user && isset($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        return null;
    }

    public function requireCurrent(?Request $request = null): int
    {
        $request ??= request();
        if ($request instanceof Request) {
            return PluginTenantContext::requireFromRequest($request);
        }

        $id = $this->current();
        if ($id === null || $id <= 0) {
            abort(422, 'tenant_id obrigatório.');
        }

        return $id;
    }
}
