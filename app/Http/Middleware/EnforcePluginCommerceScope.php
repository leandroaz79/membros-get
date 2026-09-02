<?php

namespace App\Http\Middleware;

use App\Plugins\PluginRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quando a requisição identifica um plugin (header X-Plugin-Slug), valida commerce_scopes do manifest.
 */
class EnforcePluginCommerceScope
{
    /** @var array<string, string> */
    private const ROUTE_SCOPES = [
        'commerce.catalog.products' => 'catalog:read',
        'commerce.catalog.product' => 'catalog:read',
        'commerce.cart.show' => 'cart:write',
        'commerce.cart.lines.add' => 'cart:write',
        'commerce.cart.lines.update' => 'cart:write',
        'commerce.cart.lines.remove' => 'cart:write',
        'commerce.cart.clear' => 'cart:write',
        'commerce.checkout.start' => 'checkout:start',
        'commerce.checkout.show' => 'checkout:start',
        'commerce.checkout.process' => 'checkout:start',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $slug = trim((string) ($request->header('X-Plugin-Slug') ?: $request->query('plugin_slug', '')));
        if ($slug === '') {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        $requiredScope = is_string($routeName) ? (self::ROUTE_SCOPES[$routeName] ?? null) : null;
        if ($requiredScope === null) {
            return $next($request);
        }

        $plugin = collect(PluginRegistry::enabled())->firstWhere('slug', $slug);
        if ($plugin === null) {
            abort(403, 'Plugin não habilitado.');
        }

        $scopes = $plugin['commerce_scopes'] ?? [];
        if (! is_array($scopes) || ! in_array($requiredScope, $scopes, true)) {
            abort(403, "Plugin \"{$slug}\" não possui o scope \"{$requiredScope}\".");
        }

        $request->attributes->set('plugin_commerce_slug', $slug);

        return $next($request);
    }
}
