<?php

namespace App\Services\ProductPackage;

/**
 * Schema v1 do pacote .getfy-product.
 */
final class ProductPackageManifest
{
    public const SCHEMA_VERSION = 1;

    public const FORMAT = 'getfy-product';

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string> erros de validação
     */
    public static function validate(array $manifest): array
    {
        $errors = [];

        if (($manifest['format'] ?? null) !== self::FORMAT) {
            $errors[] = 'Formato de pacote inválido (esperado getfy-product).';
        }

        $version = (int) ($manifest['schema_version'] ?? 0);
        if ($version < 1 || $version > self::SCHEMA_VERSION) {
            $errors[] = 'Versão do schema não suportada (schema_version='.$version.').';
        }

        if (! is_array($manifest['product_summary'] ?? null)) {
            $errors[] = 'Manifesto sem product_summary.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $productSummary
     * @param  list<string>  $warnings
     * @param  array<string, string>  $mediaMap old_path => archive_path
     * @return array<string, mixed>
     */
    public static function build(
        array $productSummary,
        bool $includeMedia,
        array $warnings = [],
        array $mediaMap = [],
    ): array {
        return [
            'format' => self::FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'exported_at' => now()->toIso8601String(),
            'include_media' => $includeMedia,
            'product_summary' => $productSummary,
            'warnings' => array_values($warnings),
            'media_map' => $mediaMap,
            'counts' => [
                'media_files' => count($mediaMap),
            ],
        ];
    }
}
