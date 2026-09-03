<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Camada extra quando a requisição chega ao Laravel (ex.: rewrite para index.php).
 * O bloqueio principal em hospedagem compartilhada é o .htaccess na raiz do projeto.
 */
class BlockSensitivePaths
{
    private const BLOCKED_PATTERN = '#(?:^|/)\.env(\..+)?(?:/|$)|(?:^|/)\.git(?:/|$)|(?:^|/)(?:composer\.(?:json|lock)|artisan|phpunit\.xml(?:\.dist)?)(?:/|$)#i';

    /** Prefixos de código/dados internos — não inclui storage/ (uploads públicos via Laravel). */
    private const BLOCKED_PREFIXES = [
        'vendor/',
        'bootstrap/',
        'config/',
        'database/',
        'app/',
        'routes/',
        'resources/',
        'tests/',
        'node_modules/',
        '.docker/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->path(), '/');

        if ($path !== '') {
            $normalized = '/'.trim($path, '/').'/';
            if (preg_match(self::BLOCKED_PATTERN, $normalized)) {
                abort(403);
            }
        }

        foreach (self::BLOCKED_PREFIXES as $prefix) {
            $bare = rtrim($prefix, '/');
            if ($path === $bare || str_starts_with($path, $prefix)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
