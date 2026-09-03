<?php

namespace App\Support;

class PwaIcon
{
    /** @var list<string> */
    private const DEFAULT_PATHS = [
        '/icons/icon.png',
        '/icons/icon-192x192.png',
        '/icons/icon-512x512.png',
    ];

    public static function isDefaultPath(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return true;
        }

        $path = trim($path);
        foreach (self::DEFAULT_PATHS as $default) {
            if ($path === $default || str_ends_with($path, $default)) {
                return true;
            }
        }

        return false;
    }

    public static function configuredUrl(?string $size = null): ?string
    {
        foreach (self::configKeysForSize($size) as $key) {
            $value = config($key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    public static function customConfiguredUrl(?string $size = null): ?string
    {
        foreach (self::configKeysForSize($size) as $key) {
            $value = config($key);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (! self::isDefaultPath($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function publicUrl(?string $size = null): ?string
    {
        $configured = self::configuredUrl($size);
        if ($configured === null) {
            return null;
        }

        return self::resolveToAbsoluteUrl($configured);
    }

    public static function customPublicUrl(?string $size = null): ?string
    {
        $configured = self::customConfiguredUrl($size);
        if ($configured === null) {
            return null;
        }

        return self::resolveToAbsoluteUrl($configured);
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public static function manifestIcons(): array
    {
        $icons = [];
        $addIconVariants = function (string $src, string $sizes) use (&$icons): void {
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'maskable'];
        };

        $pwa192 = self::customPublicUrl('192');
        $pwa512 = self::customPublicUrl('512');

        if ($pwa192 !== null && $pwa512 !== null) {
            $addIconVariants($pwa192, '192x192');
            $addIconVariants($pwa512, '512x512');

            return $icons;
        }

        if ($pwa192 !== null || $pwa512 !== null) {
            $icon192 = $pwa192 ?? $pwa512;
            $icon512 = $pwa512 ?? $pwa192;
            $addIconVariants($icon192, '192x192');
            $addIconVariants($icon512, '512x512');

            return $icons;
        }

        $pwaSingle = self::customPublicUrl(null);
        if ($pwaSingle !== null) {
            $addIconVariants($pwaSingle, '192x192');
            $addIconVariants($pwaSingle, '512x512');

            return $icons;
        }

        return self::defaultManifestIcons();
    }

    /**
     * @return list<string>
     */
    private static function configKeysForSize(?string $size): array
    {
        return match (strtolower((string) $size)) {
            '192' => ['getfy.pwa_icon_192', 'getfy.pwa_icon'],
            '512' => ['getfy.pwa_icon_512', 'getfy.pwa_icon'],
            default => ['getfy.pwa_icon_512', 'getfy.pwa_icon_192', 'getfy.pwa_icon'],
        };
    }

    private static function resolveToAbsoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('/'.ltrim($path, '/'));
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    private static function defaultManifestIcons(): array
    {
        $icons = [];
        $addIconVariants = function (string $src, string $sizes) use (&$icons): void {
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $src, 'sizes' => $sizes, 'type' => 'image/png', 'purpose' => 'maskable'];
        };

        $iconsDir = public_path('icons');
        $defaultIcon = $iconsDir.DIRECTORY_SEPARATOR.'icon.png';
        if (is_file($defaultIcon)) {
            $iconUrl = url('/icons/icon.png');
            $addIconVariants($iconUrl, '192x192');
            $addIconVariants($iconUrl, '512x512');

            return $icons;
        }

        $has192 = is_file($iconsDir.'/icon-192x192.png');
        $has512 = is_file($iconsDir.'/icon-512x512.png');
        $icon192Url = url('/icons/icon-192x192.png');
        $icon512Url = url('/icons/icon-512x512.png');

        if ($has192) {
            $addIconVariants($icon192Url, '192x192');
        }
        if ($has512) {
            $addIconVariants($icon512Url, '512x512');
        }
        if ($icons === []) {
            $fallbackIcon = (string) config('getfy.app_logo_icon', 'https://cdn.getfy.cloud/collapsed-logo.png');
            $addIconVariants($fallbackIcon, '192x192');
            $addIconVariants($fallbackIcon, '512x512');
        } elseif ($has512 && ! $has192) {
            $addIconVariants($icon512Url, '192x192');
        } elseif ($has192 && ! $has512) {
            $addIconVariants($icon192Url, '512x512');
        }

        return $icons;
    }
}
