<?php

namespace App\Plugins;

use Illuminate\Http\Request;

/**
 * Fila de assets estilo wp_enqueue_script / wp_enqueue_style.
 */
class PluginAssetQueue
{
    /** @var array<string, array<string, array{handle: string, url: string, defer?: bool}>> */
    private static array $scripts = [];

    /** @var array<string, array<string, array{handle: string, url: string}>> */
    private static array $styles = [];

    public static function enqueueStyle(string $handle, string $url, string $context = 'panel'): void
    {
        $context = self::normalizeContext($context);
        self::$styles[$context][$handle] = [
            'handle' => $handle,
            'url' => $url,
        ];
    }

    public static function enqueueScript(string $handle, string $url, string $context = 'panel', bool $defer = true): void
    {
        $context = self::normalizeContext($context);
        self::$scripts[$context][$handle] = [
            'handle' => $handle,
            'url' => $url,
            'defer' => $defer,
        ];
    }

    /**
     * Contexto de assets para a requisição atual (fonte única de verdade).
     */
    public static function contextForRequest(?Request $request = null): string
    {
        $request ??= request();
        if (! $request instanceof Request) {
            return 'panel';
        }

        $path = $request->path();
        if (str_starts_with($path, 'c/')
            || str_starts_with($path, 'checkout')
            || str_starts_with($path, 'api-checkout')
            || str_starts_with($path, 'commerce/checkout')) {
            return 'checkout';
        }
        if (str_starts_with($path, 'm/') || $request->attributes->get('member_area_slug')) {
            return 'member_area';
        }
        if (str_starts_with($path, 'p/')) {
            return 'public';
        }

        return 'panel';
    }

    /**
     * Dispara hooks de renderização do head para o contexto.
     */
    public static function fireHeadHooks(?string $context = null): void
    {
        $context = self::normalizeContext($context ?? self::contextForRequest());
        PluginHookBus::doAction("assets.{$context}.head");
    }

    /**
     * @return list<array{handle: string, url: string}>
     */
    public static function stylesFor(string $context): array
    {
        $context = self::normalizeContext($context);

        return array_values(self::$styles[$context] ?? []);
    }

    /**
     * @return list<array{handle: string, url: string, defer?: bool}>
     */
    public static function scriptsFor(string $context): array
    {
        $context = self::normalizeContext($context);

        return array_values(self::$scripts[$context] ?? []);
    }

    public static function reset(): void
    {
        self::$scripts = [];
        self::$styles = [];
    }

    private static function normalizeContext(string $context): string
    {
        $context = trim($context);
        if ($context === '') {
            return 'panel';
        }

        return $context;
    }
}
