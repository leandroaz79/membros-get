<?php

namespace App\Plugins;

/**
 * Capabilities declaradas por plugins (RBAC estendido).
 */
class PluginCapabilityRegistry
{
    /** @var array<string, array<string, string>> slug => [capability => label] */
    private static array $capabilities = [];

    /**
     * @param  array<string, string>  $capabilities  capability => label
     */
    public static function register(string $slug, array $capabilities): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }
        foreach ($capabilities as $cap => $label) {
            if (! is_string($cap) || $cap === '') {
                continue;
            }
            $full = self::qualify($slug, $cap);
            self::$capabilities[$slug][$full] = is_string($label) && $label !== '' ? $label : $full;
        }
    }

    public static function qualify(string $slug, string $capability): string
    {
        if (str_starts_with($capability, 'plugin:')) {
            return $capability;
        }

        return 'plugin:'.$slug.':'.$capability;
    }

    public static function isRegistered(string $permission): bool
    {
        if (! str_starts_with($permission, 'plugin:')) {
            return false;
        }
        foreach (self::$capabilities as $caps) {
            if (isset($caps[$permission])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        $flat = [];
        foreach (self::$capabilities as $caps) {
            foreach ($caps as $key => $label) {
                $flat[$key] = $label;
            }
        }

        return $flat;
    }

    public static function reset(): void
    {
        self::$capabilities = [];
    }

    /**
     * Registra capabilities do manifest (capabilities: ["manage", "view"]).
     *
     * @param  array<string, mixed>  $plugin
     */
    public static function registerFromManifest(array $plugin): void
    {
        $slug = (string) ($plugin['slug'] ?? '');
        $caps = $plugin['capabilities'] ?? null;
        if ($slug === '' || ! is_array($caps)) {
            return;
        }
        $mapped = [];
        foreach ($caps as $cap) {
            if (is_string($cap) && $cap !== '') {
                $mapped[$cap] = ucfirst(str_replace(['_', '-'], ' ', $cap));
                continue;
            }
            if (is_array($cap)) {
                $id = trim((string) ($cap['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $label = trim((string) ($cap['label'] ?? ''));
                $mapped[$id] = $label !== '' ? $label : ucfirst(str_replace(['_', '-'], ' ', $id));
            }
        }
        if ($mapped !== []) {
            self::register($slug, $mapped);
        }
    }
}
