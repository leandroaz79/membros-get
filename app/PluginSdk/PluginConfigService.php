<?php

namespace App\PluginSdk;

use App\Plugins\PluginRegistry;

/**
 * Configuração persistida por plugin (JSON na tabela plugins).
 */
class PluginConfigService
{
    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    public function get(string $slug, array $default = []): array
    {
        return PluginRegistry::getConfig($slug, $default);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function set(string $slug, array $config): void
    {
        PluginRegistry::setConfig($slug, $config);
    }

    public function getValue(string $slug, string $key, mixed $default = null): mixed
    {
        $config = $this->get($slug, []);
        if (! is_array($config)) {
            return $default;
        }

        return $config[$key] ?? $default;
    }
}
