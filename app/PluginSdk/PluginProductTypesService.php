<?php

namespace App\PluginSdk;

use App\Plugins\PluginProductTypeRegistry;

/**
 * Tipos de produto virtuais registrados por plugins.
 */
class PluginProductTypesService
{
    /**
     * @param  array{maps_to: string, label: string, description?: string, available?: bool, icon?: string}  $config
     */
    public function register(string $pluginSlug, string $virtualType, array $config): void
    {
        PluginProductTypeRegistry::register($pluginSlug, $virtualType, $config);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allForUi(): array
    {
        return PluginProductTypeRegistry::allForUi();
    }

    public function isVirtual(string $type): bool
    {
        return PluginProductTypeRegistry::isVirtual($type);
    }

    public function resolveToCoreType(string $type): ?string
    {
        return PluginProductTypeRegistry::resolveToCoreType($type);
    }
}
