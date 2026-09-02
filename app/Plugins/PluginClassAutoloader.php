<?php

namespace App\Plugins;

/**
 * Autoload PSR-4 dinâmico para plugins instalados via ZIP.
 *
 * Convenção: Plugins\{PascalSlug}\ → {pluginPath}/src/
 * Override opcional via composer.json do plugin (autoload.psr-4).
 */
class PluginClassAutoloader
{
    /** @var array<string, string> prefix => base path */
    private static array $prefixes = [];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        spl_autoload_register([self::class, 'loadClass'], true, true);
        self::refreshPrefixes();
    }

    public static function refreshPrefixes(): void
    {
        self::$prefixes = [];
        $plugins = self::pluginsToScan();
        foreach ($plugins as $plugin) {
            self::registerPluginPrefixes($plugin);
        }
    }

    public static function loadClass(string $class): bool
    {
        foreach (self::$prefixes as $prefix => $basePath) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }
            $relative = substr($class, strlen($prefix));
            $file = $basePath.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
            if (is_file($file)) {
                require_once $file;

                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{slug: string, path: string}>
     */
    private static function pluginsToScan(): array
    {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('plugins')) {
                return PluginRegistry::enabled();
            }
        } catch (\Throwable) {
        }

        return PluginRegistry::fallbackRowsWithoutDatabase();
    }

    /**
     * @param  array{slug: string, path: string}  $plugin
     */
    private static function registerPluginPrefixes(array $plugin): void
    {
        $path = rtrim((string) ($plugin['path'] ?? ''), '/\\');
        $slug = (string) ($plugin['slug'] ?? '');
        if ($path === '' || $slug === '') {
            return;
        }

        $composerFile = $path.DIRECTORY_SEPARATOR.'composer.json';
        if (is_file($composerFile)) {
            $raw = file_get_contents($composerFile);
            $json = json_decode($raw, true);
            if (is_array($json['autoload']['psr-4'] ?? null)) {
                foreach ($json['autoload']['psr-4'] as $prefix => $relativeDir) {
                    $prefix = trim((string) $prefix);
                    if ($prefix === '') {
                        continue;
                    }
                    if (! str_ends_with($prefix, '\\')) {
                        $prefix .= '\\';
                    }
                    $base = $path.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim((string) $relativeDir, '/\\'));
                    if (is_dir($base)) {
                        self::$prefixes[$prefix] = $base;
                    }
                }
            }
        }

        $srcPath = $path.DIRECTORY_SEPARATOR.'src';
        if (is_dir($srcPath)) {
            $prefix = 'Plugins\\'.self::slugToPascalCase($slug).'\\';
            self::$prefixes[$prefix] = $srcPath;
        }
    }

    public static function slugToPascalCase(string $slug): string
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($slug)) ?: [];

        return implode('', array_map(fn (string $p) => ucfirst($p), array_filter($parts)));
    }

    /** @return array<string, string> */
    public static function registeredPrefixes(): array
    {
        return self::$prefixes;
    }

    /**
     * Registro explícito de prefixo (testes ou composer.json do plugin).
     */
    public static function registerMapping(string $prefix, string $basePath): void
    {
        self::register();
        if (! str_ends_with($prefix, '\\')) {
            $prefix .= '\\';
        }
        self::$prefixes[$prefix] = rtrim($basePath, '/\\');
    }
}
