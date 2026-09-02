<?php

namespace App\Plugins;

use App\Events\PluginPublicRouteLoading;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

class PluginPublicRouteRegistrar
{
    /**
     * @param  array<string, mixed>  $plugin
     */
    public static function register(array $plugin): void
    {
        $decl = $plugin['public_routes'] ?? null;
        if ($decl === null || $decl === '' || $decl === []) {
            return;
        }

        $pluginPath = (string) ($plugin['path'] ?? '');
        $slug = (string) ($plugin['slug'] ?? '');
        if ($pluginPath === '' || $slug === '') {
            return;
        }

        $routesFile = null;
        $prefix = 'webhooks/inbound';
        $middleware = ['web', 'throttle:120,1'];

        if (is_string($decl)) {
            $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $decl);
        } elseif (is_array($decl)) {
            $file = $decl['file'] ?? $decl['path'] ?? 'routes-public.php';
            if (is_string($file) && $file !== '') {
                $routesFile = $pluginPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
            }
            if (! empty($decl['prefix']) && is_string($decl['prefix'])) {
                $prefix = trim($decl['prefix'], '/');
            }
            if (! empty($decl['middleware']) && is_array($decl['middleware'])) {
                $middleware = [];
                foreach ($decl['middleware'] as $mw) {
                    if (is_string($mw) && PluginMiddlewareRegistry::isAllowedForPublic($mw)) {
                        $middleware[] = $mw;
                    }
                }
                if ($middleware === []) {
                    $middleware = ['web', 'throttle:120,1'];
                }
            }
        }

        if ($routesFile === null || ! is_file($routesFile)) {
            return;
        }

        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            $prefix = 'p/'.$slug;
        }

        Route::middleware(array_values(array_unique($middleware)))
            ->prefix($prefix)
            ->group(function () use ($routesFile, $slug, $prefix) {
                event(new PluginPublicRouteLoading($slug, Route::getFacadeRoot(), $prefix));
                require $routesFile;
            });
    }

    /**
     * @return array<int, string>
     */
    public static function validatePublicRoutePrefixes(): array
    {
        $errors = [];
        $seen = [];
        foreach (PluginRegistry::installed() as $plugin) {
            $decl = $plugin['public_routes'] ?? null;
            if (! is_array($decl) || empty($decl['prefix'])) {
                continue;
            }
            $prefix = trim((string) $decl['prefix'], '/');
            if ($prefix === '') {
                continue;
            }
            if (isset($seen[$prefix])) {
                $errors[] = "public_routes.prefix \"{$prefix}\" duplicado ({$seen[$prefix]} e {$plugin['slug']}).";
            } else {
                $seen[$prefix] = (string) $plugin['slug'];
            }
        }

        return $errors;
    }
}
