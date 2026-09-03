<?php

use App\Plugins\PluginHookBus;

if (! function_exists('getfy_add_action')) {
    function getfy_add_action(string $hook, callable $callback, int $priority = 10): void
    {
        PluginHookBus::addAction($hook, $callback, $priority);
    }
}

if (! function_exists('getfy_add_filter')) {
    function getfy_add_filter(string $hook, callable $callback, int $priority = 10): void
    {
        PluginHookBus::addFilter($hook, $callback, $priority);
    }
}

if (! function_exists('getfy_do_action')) {
    function getfy_do_action(string $hook, mixed ...$args): void
    {
        PluginHookBus::doAction($hook, ...$args);
    }
}

if (! function_exists('getfy_apply_filters')) {
    function getfy_apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return PluginHookBus::applyFilters($hook, $value, ...$args);
    }
}
