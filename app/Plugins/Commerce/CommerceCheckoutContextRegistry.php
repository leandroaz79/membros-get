<?php

namespace App\Plugins\Commerce;

use App\Models\CommerceCheckoutSession;
use App\Models\Order;

/**
 * Handlers registrados por plugins para enriquecer checkout commerce, vendas e listeners.
 */
class CommerceCheckoutContextRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $handlers = [];

    /**
     * @param  array{
     *   supports?: callable(Order): bool,
     *   order_label?: callable(Order): ?string,
     *   line_items?: callable(Order): array,
     *   payment_summary?: callable(Order, ?CommerceCheckoutSession): array,
     *   gateway_config?: callable(Order): ?array
     * }  $handlers
     */
    public static function register(string $pluginSlug, array $handlers): void
    {
        $slug = trim($pluginSlug);
        if ($slug === '') {
            return;
        }
        self::$handlers[$slug] = array_merge(self::$handlers[$slug] ?? [], $handlers);
    }

    public static function pluginSlugForOrder(Order $order): ?string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $slug = trim((string) ($meta['plugin_checkout'] ?? ''));

        return $slug !== '' ? $slug : null;
    }

    public static function supports(Order $order): bool
    {
        $slug = self::pluginSlugForOrder($order);
        if ($slug === null) {
            return false;
        }
        $handler = self::$handlers[$slug] ?? null;
        if (! is_array($handler)) {
            return false;
        }
        $supports = $handler['supports'] ?? null;
        if (is_callable($supports)) {
            return (bool) $supports($order);
        }

        return true;
    }

    public static function resolveOrderLabel(Order $order): ?string
    {
        return self::invoke($order, 'order_label');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function resolveLineItems(Order $order): ?array
    {
        $result = self::invoke($order, 'line_items');

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<int, array{label: string, amount: float}>|null
     */
    public static function resolvePaymentSummary(Order $order, ?CommerceCheckoutSession $session = null): ?array
    {
        $slug = self::pluginSlugForOrder($order);
        if ($slug === null) {
            return null;
        }
        $handler = self::$handlers[$slug] ?? null;
        if (! is_array($handler)) {
            return null;
        }
        $callable = $handler['payment_summary'] ?? null;
        if (! is_callable($callable)) {
            return null;
        }
        $result = $callable($order, $session);

        return is_array($result) ? $result : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function resolveGatewayConfig(Order $order): ?array
    {
        $result = self::invoke($order, 'gateway_config');

        return is_array($result) ? $result : null;
    }

    private static function invoke(Order $order, string $key): mixed
    {
        $slug = self::pluginSlugForOrder($order);
        if ($slug === null) {
            return null;
        }
        $handler = self::$handlers[$slug] ?? null;
        if (! is_array($handler)) {
            return null;
        }
        $callable = $handler[$key] ?? null;
        if (! is_callable($callable)) {
            return null;
        }

        return $callable($order);
    }
}
