<?php

namespace App\Support;

class StoredFileUrl
{
    public static function isValid(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, '/storage/')) {
            return self::isSafeStoragePath(ltrim(substr($url, 9), '/'));
        }

        if (str_starts_with($url, 'storage/')) {
            return self::isSafeStoragePath(ltrim(substr($url, 8), '/'));
        }

        if (str_starts_with($url, 'member-area/') || str_starts_with($url, 'member-pdf-library/')) {
            return self::isSafeStoragePath($url);
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url)) {
            $path = parse_url($url, PHP_URL_PATH);
            if (is_string($path) && str_starts_with($path, '/storage/')) {
                return $path;
            }
        }

        if (str_starts_with($url, 'storage/')) {
            return '/'.ltrim($url, '/');
        }

        if (str_starts_with($url, 'member-area/') || str_starts_with($url, 'member-pdf-library/')) {
            return '/storage/'.$url;
        }

        return $url;
    }

    private static function isSafeStoragePath(string $path): bool
    {
        $path = trim($path, '/');
        if ($path === '' || str_contains($path, '..')) {
            return false;
        }

        return (bool) preg_match('#^[a-zA-Z0-9/_.\-]+$#', $path);
    }
}
