<?php

namespace App\Plugins;

use Illuminate\Support\Facades\Route;

class PluginApiRouteRegistrar
{
    /**
     * @param  array<string, mixed>  $plugin
     */
    public static function register(array $plugin): void
    {
        $decl = $plugin['api_routes'] ?? null;
        if ($decl === null || $decl === '' || $decl === []) {
            return;
        }

        $pluginPath = (string) ($plugin['path'] ?? '');
        $slug = (string) ($plugin['slug'] ?? '');
        if ($pluginPath === '' || $slug === '') {
            return;
        }

        $routesFile = null;
        $prefix = 'api/plugins/'.$slug;
        $middleware = ['api', 'plugin.api.signature', 'throttle:120,1'];

        if (is_string($decl)) {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $decl);
        } elseif (is_array($decl)) {
            $file = $decl['file'] ?? $decl['path'] ?? 'routes-api.php';
            if (is_string($file) && $file !== '') {
                $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
            }
            if (! empty($decl['prefix']) && is_string($decl['prefix'])) {
                $prefix = trim($decl['prefix'], '/');
            }
            if (! empty($decl['middleware']) && is_array($decl['middleware'])) {
                $filtered = [];
                foreach ($decl['middleware'] as $mw) {
                    if (is_string($mw) && PluginMiddlewareRegistry::isAllowedForApi($mw)) {
                        $filtered[] = $mw;
                    }
                }
                if ($filtered !== []) {
                    $middleware = $filtered;
                }
            }
        }

        if ($routesFile === null || ! is_file($routesFile)) {
            return;
        }

        $prefix = trim($prefix, '/');
        $load = static function () use ($routesFile) {
            require $routesFile;
        };
        Route::middleware(array_values(array_unique($middleware)))
            ->prefix($prefix)
            ->group($load);

        if (! str_starts_with($prefix, 'api/v1/')) {
            Route::middleware(array_values(array_unique($middleware)))
                ->prefix('api/v1/plugins/'.$slug)
                ->group($load);
        }
    }

    /**
     * @return array<int, string>
     */
    public static function validateApiRoutePrefixes(): array
    {
        $errors = [];
        $seen = [];
        foreach (PluginRegistry::installed() as $plugin) {
            $decl = $plugin['api_routes'] ?? null;
            if (! is_array($decl) || empty($decl['prefix'])) {
                continue;
            }
            $prefix = trim((string) $decl['prefix'], '/');
            if ($prefix === '') {
                continue;
            }
            if (isset($seen[$prefix])) {
                $errors[] = "api_routes.prefix \"{$prefix}\" duplicado ({$seen[$prefix]} e {$plugin['slug']}).";
            } else {
                $seen[$prefix] = (string) $plugin['slug'];
            }
        }

        return $errors;
    }
}
