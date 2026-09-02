<?php

namespace App\PluginSdk;

use App\Plugins\PluginCapabilityRegistry;

/**
 * Capabilities RBAC de plugins (plugin:{slug}:{cap}).
 */
class PluginCapabilitiesService
{
    /**
     * @param  array<string, string>  $capabilities  capability => label
     */
    public function register(string $slug, array $capabilities): void
    {
        PluginCapabilityRegistry::register($slug, $capabilities);
    }

    public function qualify(string $slug, string $capability): string
    {
        return PluginCapabilityRegistry::qualify($slug, $capability);
    }

    public function isRegistered(string $permission): bool
    {
        return PluginCapabilityRegistry::isRegistered($permission);
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return PluginCapabilityRegistry::all();
    }
}
