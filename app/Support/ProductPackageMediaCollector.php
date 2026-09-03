<?php

namespace App\Support;

use App\Services\StorageService;

/**
 * Descobre paths de storage em estruturas JSON e reescreve URLs/paths.
 */
final class ProductPackageMediaCollector
{
    /** @var array<string, true> */
    private array $seen = [];

    /** @var list<string> */
    private array $paths = [];

    public function __construct(
        private readonly StorageService $storage,
    ) {}

    /**
     * @return list<string>
     */
    public function paths(): array
    {
        return $this->paths;
    }

    public function collectFromValue(mixed $value): void
    {
        if (is_string($value)) {
            $this->maybeAdd($value);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectFromValue($item);
        }
    }

    private function maybeAdd(string $value): void
    {
        $value = trim($value);
        if ($value === '' || strlen($value) < 4) {
            return;
        }

        // Ignora data URIs / HTML / texto longo sem cara de path
        if (str_starts_with($value, 'data:') || str_contains($value, '<')) {
            return;
        }

        $path = $this->storage->pathFromUrl($value);
        if ($path === null) {
            // Path relativo já no disco (products/, checkout/, …)
            if ($this->looksLikeStoragePath($value) && $this->storage->exists($value)) {
                $path = ltrim($value, '/');
            }
        }

        if ($path === null || $path === '') {
            return;
        }

        if (isset($this->seen[$path])) {
            return;
        }

        if (! $this->storage->exists($path)) {
            return;
        }

        $this->seen[$path] = true;
        $this->paths[] = $path;
    }

    private function looksLikeStoragePath(string $value): bool
    {
        return (bool) preg_match(
            '#^(products|checkout|member-area|member-area-gamification|member-area-posts|email-templates|member-pdf-library)/#',
            $value
        );
    }

    /**
     * Reescreve strings que apontam para paths conhecidos.
     *
     * @param  array<string, string>  $pathToUrl old_path => new_public_url_or_path
     */
    public static function rewriteValue(mixed $value, array $pathToUrl, StorageService $storage): mixed
    {
        if (is_string($value)) {
            return self::rewriteString($value, $pathToUrl, $storage);
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::rewriteValue($item, $pathToUrl, $storage);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $pathToUrl
     */
    public static function rewriteString(string $value, array $pathToUrl, StorageService $storage): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return $value;
        }

        $path = $storage->pathFromUrl($trimmed);
        if ($path === null && isset($pathToUrl[$trimmed])) {
            return $pathToUrl[$trimmed];
        }

        if ($path !== null && isset($pathToUrl[$path])) {
            return $pathToUrl[$path];
        }

        // Path órfão local sem mídia no pacote → limpa campos de imagem
        if ($path !== null && ! isset($pathToUrl[$path]) && self::isLikelyMediaFieldValue($trimmed)) {
            return '';
        }

        return $value;
    }

    private static function isLikelyMediaFieldValue(string $value): bool
    {
        return (bool) preg_match('#\.(jpe?g|png|gif|webp|svg|pdf|mp4|webm)(\?|$)#i', $value)
            || str_contains($value, '/storage/')
            || (bool) preg_match('#^(products|checkout|member-area)/#', $value);
    }

    /**
     * Substitui o prefixo de product id em paths conhecidos.
     *
     * @param  array<string, string>  $oldPathToNewPath
     * @return array<string, string> old_path => new_public_url
     */
    public static function buildUrlMap(array $oldPathToNewPath, StorageService $storage): array
    {
        $map = [];
        foreach ($oldPathToNewPath as $old => $new) {
            $map[$old] = $storage->url($new);
        }

        return $map;
    }
}
