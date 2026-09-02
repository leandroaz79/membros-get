<?php

namespace App\PluginSdk;

use App\Plugins\PluginHookBus;

/**
 * API pública de hooks para plugins.
 */
class PluginHooksService
{
    public function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        PluginHookBus::addAction($hook, $callback, $priority);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        PluginHookBus::addFilter($hook, $callback, $priority);
    }

    public function doAction(string $hook, mixed ...$args): void
    {
        PluginHookBus::doAction($hook, ...$args);
    }

    public function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return PluginHookBus::applyFilters($hook, $value, ...$args);
    }
}
