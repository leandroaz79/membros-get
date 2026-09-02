<?php

namespace App\Plugins;

/**
 * Whitelist central de middleware para rotas de plugins.
 */
class PluginMiddlewareRegistry
{
    /** @var list<string> */
    private const PANEL_ALIASES = [
        'web',
        'auth',
        'throttle:60,1',
        'throttle:120,1',
        'role:admin|infoprodutor',
        'role:admin|infoprodutor|team',
        'verified',
    ];

    /** @var list<string> */
    private const API_ALIASES = [
        'api',
        'web',
        'auth',
        'plugin.api.signature',
        'throttle:60,1',
        'throttle:120,1',
        'plugin.commerce.scope',
    ];

    /** @var list<string> */
    private const PUBLIC_ALIASES = [
        'web',
        'storefront.tenant',
        'throttle:60,1',
        'throttle:120,1',
        'plugin.commerce.scope',
    ];

    /** @var array<string, class-string> slug => FQCN */
    private static array $pluginMiddleware = [];

    /**
     * @param  class-string  $class
     */
    public static function registerPluginMiddleware(string $slug, string $class): void
    {
        $slug = trim($slug);
        if ($slug === '' || ! class_exists($class)) {
            return;
        }
        $expectedPrefix = 'Plugins\\'.self::studlySlug($slug).'\\Http\\Middleware\\';
        if (! str_starts_with($class, $expectedPrefix)) {
            return;
        }
        self::$pluginMiddleware[$slug.':'.$class] = $class;
    }

    public static function isAllowedForPanel(string $middleware): bool
    {
        return self::matches(self::PANEL_ALIASES, $middleware);
    }

    public static function isAllowedForApi(string $middleware): bool
    {
        return self::matches(self::API_ALIASES, $middleware);
    }

    public static function isAllowedForPublic(string $middleware): bool
    {
        return self::matches(self::PUBLIC_ALIASES, $middleware);
    }

    /**
     * @return array<int, string>
     */
    public static function validateManifestEntries(array $manifest): array
    {
        $errors = [];
        $panel = $manifest['middleware'] ?? null;
        if (is_array($panel)) {
            foreach ($panel as $mw) {
                if (is_string($mw) && $mw !== '' && ! self::isAllowedForPanel($mw)) {
                    $errors[] = "middleware inválido no painel: {$mw}";
                }
            }
        }
        foreach (['api_routes', 'public_routes'] as $key) {
            $decl = $manifest[$key] ?? null;
            if (! is_array($decl)) {
                continue;
            }
            $list = $decl['middleware'] ?? null;
            if (! is_array($list)) {
                continue;
            }
            $checker = $key === 'api_routes' ? 'isAllowedForApi' : 'isAllowedForPublic';
            foreach ($list as $mw) {
                if (is_string($mw) && $mw !== '' && ! self::{$checker}($mw)) {
                    $errors[] = "{$key}.middleware inválido: {$mw}";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  list<string>  $aliases
     */
    private static function matches(array $aliases, string $middleware): bool
    {
        if (in_array($middleware, $aliases, true)) {
            return true;
        }
        if (str_starts_with($middleware, 'team.permission:')) {
            $permission = substr($middleware, strlen('team.permission:'));
            if ($permission === '') {
                return false;
            }
            if (! str_starts_with($permission, 'plugin:')) {
                return true;
            }

            return PluginCapabilityRegistry::isRegistered($permission);
        }

        return false;
    }

    private static function studlySlug(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }
}
