<?php

namespace App\Plugins;

/**
 * Sistema de hooks estilo WordPress (actions + filters).
 *
 * Actions: callbacks recebem argumentos, sem retorno.
 * Filters: callbacks recebem valor + argumentos extras, retornam valor transformado.
 */
class PluginHookBus
{
    /** @var array<string, array<int, list<array{callable: callable, priority: int}>>> */
    private static array $actions = [];

    /** @var array<string, array<int, list<array{callable: callable, priority: int}>>> */
    private static array $filters = [];

    public static function addAction(string $hook, callable $callback, int $priority = 10): void
    {
        self::register(self::$actions, $hook, $callback, $priority);
    }

    public static function addFilter(string $hook, callable $callback, int $priority = 10): void
    {
        self::register(self::$filters, $hook, $callback, $priority);
    }

    public static function removeAction(string $hook, callable $callback, int $priority = 10): bool
    {
        return self::remove(self::$actions, $hook, $callback, $priority);
    }

    public static function removeFilter(string $hook, callable $callback, int $priority = 10): bool
    {
        return self::remove(self::$filters, $hook, $callback, $priority);
    }

    /**
     * @param  mixed  ...$args
     */
    public static function doAction(string $hook, mixed ...$args): void
    {
        foreach (self::sortedCallbacks(self::$actions, $hook) as $entry) {
            ($entry['callable'])(...$args);
        }
    }

    /**
     * @param  mixed  $value
     * @param  mixed  ...$args
     * @return mixed
     */
    public static function applyFilters(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach (self::sortedCallbacks(self::$filters, $hook) as $entry) {
            $value = ($entry['callable'])($value, ...$args);
        }

        return $value;
    }

    public static function hasAction(string $hook): bool
    {
        return ! empty(self::$actions[$hook]);
    }

    public static function hasFilter(string $hook): bool
    {
        return ! empty(self::$filters[$hook]);
    }

    /** @return list<string> */
    public static function registeredActions(): array
    {
        return array_keys(self::$actions);
    }

    /** @return list<string> */
    public static function registeredFilters(): array
    {
        return array_keys(self::$filters);
    }

    /**
     * Limpa todos os hooks (útil em testes).
     */
    public static function reset(): void
    {
        self::$actions = [];
        self::$filters = [];
    }

    /**
     * @param  array<string, array<int, list<array{callable: callable, priority: int}>>>  $store
     */
    private static function register(array &$store, string $hook, callable $callback, int $priority): void
    {
        $hook = trim($hook);
        if ($hook === '') {
            return;
        }
        $store[$hook][$priority][] = [
            'callable' => $callback,
            'priority' => $priority,
        ];
    }

    /**
     * @param  array<string, array<int, list<array{callable: callable, priority: int}>>>  $store
     */
    private static function remove(array &$store, string $hook, callable $callback, int $priority): bool
    {
        if (! isset($store[$hook][$priority])) {
            return false;
        }
        $before = count($store[$hook][$priority]);
        $store[$hook][$priority] = array_values(array_filter(
            $store[$hook][$priority],
            fn (array $entry) => $entry['callable'] !== $callback
        ));
        if ($store[$hook][$priority] === []) {
            unset($store[$hook][$priority]);
        }
        if (isset($store[$hook]) && $store[$hook] === []) {
            unset($store[$hook]);
        }

        return count($store[$hook][$priority] ?? []) < $before;
    }

    /**
     * @param  array<string, array<int, list<array{callable: callable, priority: int}>>>  $store
     * @return list<array{callable: callable, priority: int}>
     */
    private static function sortedCallbacks(array $store, string $hook): array
    {
        if (! isset($store[$hook])) {
            return [];
        }
        $priorities = array_keys($store[$hook]);
        sort($priorities, SORT_NUMERIC);
        $callbacks = [];
        foreach ($priorities as $priority) {
            foreach ($store[$hook][$priority] as $entry) {
                $callbacks[] = $entry;
            }
        }

        return $callbacks;
    }
}
