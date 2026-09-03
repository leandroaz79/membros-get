<?php

namespace App\Http\Middleware;

use App\Plugins\PluginRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida HMAC para rotas api/plugins/{slug} quando não há sessão autenticada.
 * Header: X-Plugin-Signature = hash_hmac('sha256', raw_body, secret)
 * Secret: PluginRegistry::getConfig($slug)['api_secret'] ou plugins.config no DB.
 */
class VerifyPluginApiSignature
{
    /**
     * @param  \Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()) {
            return $next($request);
        }

        $slug = $this->resolvePluginSlug($request);
        if ($slug === null) {
            abort(401, 'Plugin não identificado.');
        }

        $config = PluginRegistry::getConfig($slug);
        $secret = is_array($config) ? (string) ($config['api_secret'] ?? '') : '';
        if ($secret === '') {
            abort(401, 'API secret do plugin não configurado.');
        }

        $signature = (string) $request->header('X-Plugin-Signature', '');
        if ($signature === '') {
            abort(401, 'Assinatura ausente.');
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        if (! hash_equals($expected, $signature)) {
            abort(401, 'Assinatura inválida.');
        }

        $request->attributes->set('plugin_api_slug', $slug);

        return $next($request);
    }

    private function resolvePluginSlug(Request $request): ?string
    {
        $path = trim($request->path(), '/');
        if (! str_starts_with($path, 'api/plugins/')) {
            return null;
        }
        $parts = explode('/', $path);

        return $parts[2] ?? null;
    }
}
