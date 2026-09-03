<?php

namespace App\PluginSdk;

use App\Plugins\PluginCheckoutExtensionRegistry;
use App\Plugins\PluginExtensionRegistry;

/**
 * Extensões de UI (slots, checkout, zones) para plugins.
 */
class PluginExtensionsService
{
    /**
     * @param  array<string, mixed>  $extension
     */
    public function register(string $slug, array $extension = []): void
    {
        PluginExtensionRegistry::register($slug, $extension);
    }

    /**
     * @param  array<string, mixed>  $extension
     */
    public function registerCheckout(string $slug, array $extension = []): void
    {
        PluginCheckoutExtensionRegistry::register($slug, $extension);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $slug): ?array
    {
        return PluginExtensionRegistry::getBootstrapExtension($slug);
    }

    public function assetUrl(string $slug, string $relativePath): ?string
    {
        return PluginExtensionRegistry::assetUrl($slug, $relativePath);
    }
}
