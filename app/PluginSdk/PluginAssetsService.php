<?php

namespace App\PluginSdk;

use App\Plugins\PluginAssetQueue;
use App\Plugins\PluginExtensionRegistry;

/**
 * URLs de assets estáticos do plugin (assets/, dist/).
 */
class PluginAssetsService
{
    public function url(string $slug, string $relativePath): ?string
    {
        return PluginExtensionRegistry::assetUrl($slug, $relativePath);
    }

    public function enqueueStyle(string $slug, string $handle, string $relativePath, string $context = 'panel'): void
    {
        $url = $this->url($slug, $relativePath);
        if ($url !== null) {
            PluginAssetQueue::enqueueStyle($handle, $url, $context);
        }
    }

    public function enqueueScript(string $slug, string $handle, string $relativePath, string $context = 'panel', bool $defer = true): void
    {
        $url = $this->url($slug, $relativePath);
        if ($url !== null) {
            PluginAssetQueue::enqueueScript($handle, $url, $context, $defer);
        }
    }
}
