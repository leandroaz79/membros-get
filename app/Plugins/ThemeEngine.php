<?php

namespace App\Plugins;

use App\PluginSdk\Getfy;
use Illuminate\Http\Request;

/**
 * Motor de temas para plugins (theme.json no manifest).
 */
class ThemeEngine
{
    public static function apply(?Request $request = null): void
    {
        $request ??= request();
        $tenantId = Getfy::tenant()->current($request instanceof Request ? $request : null);
        $theme = self::resolveActiveTheme($tenantId);
        if ($theme === null) {
            return;
        }

        self::applyConfigTokens($theme, $request, $tenantId);
        self::queueThemeAssets($theme, $request);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveActiveTheme(?int $tenantId): ?array
    {
        foreach (PluginRegistry::enabled() as $plugin) {
            $themeDecl = $plugin['theme'] ?? null;
            if (! is_array($themeDecl)) {
                continue;
            }
            $config = Getfy::config()->get((string) ($plugin['slug'] ?? ''));
            $activeThemeId = is_array($config) ? ($config['active_theme_id'] ?? $themeDecl['id'] ?? null) : ($themeDecl['id'] ?? null);
            if ($activeThemeId !== null && $activeThemeId !== ($themeDecl['id'] ?? null)) {
                continue;
            }

            return [
                'plugin' => $plugin,
                'declaration' => $themeDecl,
            ];
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public static function tokensForRequest(?Request $request = null, ?int $tenantId = null): array
    {
        $request ??= request();
        $tenantId ??= Getfy::tenant()->current($request instanceof Request ? $request : null);
        $theme = self::resolveActiveTheme($tenantId);
        if ($theme === null) {
            return [];
        }

        $tokens = self::readTokensFile($theme);
        $mapped = [];
        foreach ($tokens as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $cssKey = str_starts_with($key, '--') ? $key : '--getfy-'.str_replace('_', '-', $key);
                $mapped[$cssKey] = (string) $value;
            }
        }

        return PluginHookBus::applyFilters('theme.tokens', $mapped, $request, $tenantId);
    }

    /**
     * @param  array{plugin: array<string, mixed>, declaration: array<string, mixed>}  $theme
     */
    private static function applyConfigTokens(array $theme, ?Request $request, ?int $tenantId): void
    {
        $tokens = self::tokensForRequest($request, $tenantId);
        if (isset($tokens['--getfy-primary']) || isset($tokens['--getfy-theme-primary'])) {
            $primary = $tokens['--getfy-primary'] ?? $tokens['--getfy-theme-primary'];
            config(['getfy.theme_primary' => $primary]);
        }
        if (isset($tokens['--getfy-app-name'])) {
            config(['getfy.app_name' => $tokens['--getfy-app-name']]);
        }
    }

    /**
     * @param  array{plugin: array<string, mixed>, declaration: array<string, mixed>}  $theme
     */
    private static function queueThemeAssets(array $theme, ?Request $request): void
    {
        $slug = (string) ($theme['plugin']['slug'] ?? '');
        $decl = $theme['declaration'];
        $context = self::detectContext($request);
        $targets = is_array($decl['targets'] ?? null) ? $decl['targets'] : ['panel'];
        if (! in_array($context, $targets, true) && ! in_array('all', $targets, true)) {
            return;
        }

        $styles = is_array($decl['styles'] ?? null) ? $decl['styles'] : [];
        foreach ($styles as $relative) {
            if (! is_string($relative) || $relative === '') {
                continue;
            }
            $url = PluginExtensionRegistry::assetUrl($slug, $relative);
            if ($url !== null) {
                PluginAssetQueue::enqueueStyle('theme-'.$slug.'-'.md5($relative), $url, $context);
            }
        }

        $styleList = PluginHookBus::applyFilters('theme.styles', [], $context);
        foreach ($styleList as $idx => $url) {
            if (is_string($url) && $url !== '') {
                PluginAssetQueue::enqueueStyle('theme-filter-'.$idx, $url, $context);
            }
        }
    }

    /**
     * @param  array{plugin: array<string, mixed>, declaration: array<string, mixed>}  $theme
     * @return array<string, mixed>
     */
    private static function readTokensFile(array $theme): array
    {
        $path = (string) ($theme['plugin']['path'] ?? '');
        $tokensFile = (string) ($theme['declaration']['tokens'] ?? '');
        if ($path === '' || $tokensFile === '') {
            return is_array($theme['declaration']['tokens_inline'] ?? null)
                ? $theme['declaration']['tokens_inline']
                : [];
        }
        $full = $path.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $tokensFile);
        if (! is_file($full)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($full), true);

        return is_array($json) ? $json : [];
    }

    private static function detectContext(?Request $request): string
    {
        return PluginAssetQueue::contextForRequest($request instanceof Request ? $request : null);
    }

    public static function registerViewNamespaces(): void
    {
        foreach (PluginRegistry::enabled() as $plugin) {
            $themeDecl = $plugin['theme'] ?? null;
            if (! is_array($themeDecl)) {
                continue;
            }
            $templates = $themeDecl['templates'] ?? null;
            if (! is_array($templates)) {
                continue;
            }
            $slug = preg_replace('/[^a-z0-9_\-]/', '_', (string) ($plugin['slug'] ?? 'theme'));
            $themePath = rtrim((string) ($plugin['path'] ?? ''), '/\\').DIRECTORY_SEPARATOR.'theme';
            if (is_dir($themePath)) {
                app('view')->addNamespace('plugin-theme.'.$slug, $themePath);
            }
        }
    }
}
