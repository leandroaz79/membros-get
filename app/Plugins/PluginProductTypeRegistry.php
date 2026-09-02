<?php

namespace App\Plugins;

/**
 * Tipos de produto virtuais registrados por plugins (mapeados para tipos core na persistência).
 */
class PluginProductTypeRegistry
{
    /** @var array<string, array{plugin_slug: string, maps_to: string, label: string, description: string, available: bool, icon: ?string}> */
    private static array $types = [];

    /**
     * @param  array{maps_to: string, label: string, description?: string, available?: bool, icon?: string}  $config
     */
    public static function register(string $pluginSlug, string $virtualType, array $config): void
    {
        $pluginSlug = trim($pluginSlug);
        $virtualType = trim($virtualType);
        if ($pluginSlug === '' || $virtualType === '') {
            return;
        }

        self::$types[$virtualType] = [
            'plugin_slug' => $pluginSlug,
            'maps_to' => trim((string) ($config['maps_to'] ?? '')),
            'label' => trim((string) ($config['label'] ?? $virtualType)),
            'description' => trim((string) ($config['description'] ?? '')),
            'available' => (bool) ($config['available'] ?? true),
            'icon' => isset($config['icon']) ? trim((string) $config['icon']) : null,
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, available: bool, icon: ?string, plugin_slug: string, virtual: bool}>
     */
    public static function allForUi(): array
    {
        $out = [];
        foreach (self::$types as $value => $row) {
            if (($row['maps_to'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'value' => $value,
                'label' => $row['label'],
                'description' => $row['description'],
                'available' => $row['available'],
                'icon' => $row['icon'],
                'plugin_slug' => $row['plugin_slug'],
                'virtual' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function allowedTypeValues(array $coreTypes): array
    {
        return array_values(array_unique(array_merge($coreTypes, array_keys(self::$types))));
    }

    public static function isVirtual(string $type): bool
    {
        return isset(self::$types[trim($type)]);
    }

    public static function resolveToCoreType(string $type): ?string
    {
        $type = trim($type);
        if (! isset(self::$types[$type])) {
            return null;
        }
        $mapsTo = trim((string) (self::$types[$type]['maps_to'] ?? ''));

        return $mapsTo !== '' ? $mapsTo : null;
    }

    /**
     * @return array{slug: string, virtual_type: string}|null
     */
    public static function markerFor(string $virtualType): ?array
    {
        $virtualType = trim($virtualType);
        if (! isset(self::$types[$virtualType])) {
            return null;
        }

        return [
            'slug' => self::$types[$virtualType]['plugin_slug'],
            'virtual_type' => $virtualType,
        ];
    }

    /**
     * @return array{slug: string, virtual_type: string}|null
     */
    public static function markerFromProduct(\App\Models\Product $product): ?array
    {
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $marker = $config['_plugin_product'] ?? null;

        return is_array($marker) && ! empty($marker['slug']) && ! empty($marker['virtual_type'])
            ? ['slug' => (string) $marker['slug'], 'virtual_type' => (string) $marker['virtual_type']]
            : null;
    }

    public static function isPhysicalProductMarker(\App\Models\Product $product): bool
    {
        $marker = self::markerFromProduct($product);

        return $marker !== null && $marker['virtual_type'] === 'produto_fisico';
    }

    /**
     * Normaliza tipo virtual → core e aplica marcador em checkout_config.
     *
     * @param  array<string, mixed>  $validated
     * @return array{validated: array<string, mixed>, virtual_type: ?string}
     */
    public static function normalizeValidatedType(array $validated): array
    {
        $type = (string) ($validated['type'] ?? '');
        if (! self::isVirtual($type)) {
            return ['validated' => $validated, 'virtual_type' => null];
        }

        $virtualType = $type;
        $coreType = self::resolveToCoreType($type);
        if ($coreType === null) {
            return ['validated' => $validated, 'virtual_type' => null];
        }

        $validated['type'] = $coreType;
        $marker = self::markerFor($virtualType);
        if ($marker !== null) {
            $config = is_array($validated['checkout_config'] ?? null) ? $validated['checkout_config'] : [];
            $config['_plugin_product'] = $marker;
            $validated['checkout_config'] = $config;
        }

        return ['validated' => $validated, 'virtual_type' => $virtualType];
    }

    /**
     * Aplica marcador em produto existente após update de tipo virtual.
     */
    public static function applyMarkerToProduct(\App\Models\Product $product, string $virtualType): void
    {
        $marker = self::markerFor($virtualType);
        if ($marker === null) {
            return;
        }
        $config = is_array($product->checkout_config) ? $product->checkout_config : [];
        $config['_plugin_product'] = $marker;
        $product->checkout_config = $config;
    }
}
