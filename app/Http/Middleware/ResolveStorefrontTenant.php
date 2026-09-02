<?php

namespace App\Http\Middleware;

use App\Services\StorefrontTenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveStorefrontTenant
{
    /**
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolved = StorefrontTenantResolver::resolveFromHost($request);
        if ($resolved !== null) {
            $request->attributes->set('storefront_tenant_id', $resolved['tenant_id']);
            $request->attributes->set('storefront_plugin_slug', $resolved['plugin_slug']);
        }

        return $next($request);
    }
}
