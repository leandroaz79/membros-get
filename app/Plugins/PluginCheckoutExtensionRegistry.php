<?php

namespace App\Plugins;

use App\Models\Order;
use App\Models\Product;

/**
 * Extensões de checkout (UI + processamento) registradas por plugins.
 */
class PluginCheckoutExtensionRegistry
{
    /** @var array<string, array<string, mixed>> */
    private static array $bootstrapExtensions = [];

    /**
     * @param  array<string, mixed>  $extension
     */
    public static function register(string $slug, array $extension = []): void
    {
        $slug = trim($slug);
        if ($slug === '') {
            return;
        }
        self::$bootstrapExtensions[$slug] = array_merge(self::$bootstrapExtensions[$slug] ?? [], $extension);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getBootstrapExtension(string $slug): ?array
    {
        return self::$bootstrapExtensions[$slug] ?? null;
    }

    /**
     * Extensões ativas para um produto no checkout padrão.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForProduct(Product $product, string $context = 'standard'): array
    {
        $items = [];
        foreach (PluginRegistry::enabled() as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $extensions = $plugin['checkout_extensions'] ?? null;
            if (! is_array($extensions)) {
                continue;
            }

            $resolver = self::$bootstrapExtensions[$slug]['checkout_active_resolver'] ?? null;
            if (is_callable($resolver) && ! $resolver($product, $context)) {
                continue;
            }

            foreach ($extensions as $ext) {
                if (! is_array($ext)) {
                    continue;
                }
                $extContext = (string) ($ext['context'] ?? 'standard');
                if ($extContext !== 'all' && $extContext !== $context) {
                    continue;
                }
                $slotKey = 'checkout_extension';
                $export = trim((string) ($ext['export'] ?? ''));
                if ($export === '') {
                    $export = PluginExtensionRegistry::resolveExportForSlot($plugin, $slotKey);
                }
                $row = array_merge($ext, [
                    'id' => (string) ($ext['id'] ?? $slug),
                    'plugin_slug' => $slug,
                ]);
                if ($export !== '' && PluginExtensionRegistry::hasRuntimeFrontend($plugin)) {
                    $row['ui_mode'] = 'runtime';
                    $row['ui_export'] = $export;
                } else {
                    $component = trim((string) ($ext['component'] ?? ''));
                    $row['ui_mode'] = $component !== '' ? 'legacy' : 'runtime';
                    $row['component'] = $component;
                }
                $items[] = $row;
            }
        }

        return $items;
    }

    /**
     * Extensões ativas para pedido commerce checkout.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeForOrder(Order $order, string $context = 'commerce'): array
    {
        $product = $order->product;
        if ($product === null) {
            return [];
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $pluginSlug = trim((string) ($meta['plugin_checkout'] ?? ''));
        if ($pluginSlug !== '') {
            $items = [];
            foreach (PluginRegistry::enabled() as $plugin) {
                if ((string) ($plugin['slug'] ?? '') !== $pluginSlug) {
                    continue;
                }
                $extensions = $plugin['checkout_extensions'] ?? null;
                if (! is_array($extensions)) {
                    break;
                }
                foreach ($extensions as $ext) {
                    if (! is_array($ext)) {
                        continue;
                    }
                    $extContext = (string) ($ext['context'] ?? 'commerce');
                    if ($extContext !== 'all' && $extContext !== $context && $extContext !== 'commerce') {
                        continue;
                    }
                    $export = trim((string) ($ext['export'] ?? ''));
                    if ($export === '') {
                        $export = PluginExtensionRegistry::resolveExportForSlot($plugin, 'checkout_extension') ?? '';
                    }
                    $items[] = array_merge($ext, [
                        'id' => (string) ($ext['id'] ?? $pluginSlug),
                        'plugin_slug' => $pluginSlug,
                        'ui_mode' => 'runtime',
                        'ui_export' => $export,
                    ]);
                }

                return $items;
            }
        }

        return self::activeForProduct($product, $context);
    }

    /**
     * Invoca handlers de processamento registrados no bootstrap.
     *
     * @param  array<string, mixed>  $pluginCheckoutData
     */
    public static function invokeProcessHandlers(
        Product $product,
        array $validated,
        array $pluginCheckoutData,
        \App\Events\CheckoutBeforeProcess $event,
    ): void {
        foreach (PluginRegistry::enabled() as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            $handler = self::$bootstrapExtensions[$slug]['checkout_process_handler'] ?? null;
            if (! is_callable($handler)) {
                continue;
            }
            $slugData = is_array($pluginCheckoutData[$slug] ?? null) ? $pluginCheckoutData[$slug] : [];
            $handler($product, $validated, $slugData, $event);
        }
    }

    /**
     * Decodifica plugin_checkout_data do request.
     *
     * @return array<string, mixed>
     */
    public static function decodeCheckoutDataFromRequest(\Illuminate\Http\Request $request): array
    {
        $raw = $request->input('plugin_checkout_data');
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
