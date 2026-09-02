<?php

namespace App\PluginSdk;

/**
 * Fachada pública estável para plugins Getfy.
 *
 * Use apenas classes em App\PluginSdk\* — o restante do app/ é interno.
 *
 * @see docs/developers/sdk-reference.md
 */
final class Getfy
{
    private static ?PluginConfigService $config = null;

    private static ?PluginTenantService $tenant = null;

    private static ?PluginProductsService $products = null;

    private static ?PluginOrdersService $orders = null;

    private static ?PluginCommerceService $commerce = null;

    private static ?PluginAssetsService $assets = null;

    private static ?PluginHooksService $hooks = null;

    private static ?PluginGatewaysService $gateways = null;

    private static ?PluginExtensionsService $extensions = null;

    private static ?PluginProductTypesService $productTypes = null;

    private static ?PluginCapabilitiesService $capabilities = null;

    private static ?PluginEventsService $events = null;

    private static ?PluginLicenseService $license = null;

    public static function config(): PluginConfigService
    {
        return self::$config ??= new PluginConfigService;
    }

    public static function tenant(): PluginTenantService
    {
        return self::$tenant ??= new PluginTenantService;
    }

    public static function products(): PluginProductsService
    {
        return self::$products ??= new PluginProductsService;
    }

    public static function orders(): PluginOrdersService
    {
        return self::$orders ??= new PluginOrdersService;
    }

    public static function commerce(): PluginCommerceService
    {
        return self::$commerce ??= new PluginCommerceService;
    }

    public static function assets(): PluginAssetsService
    {
        return self::$assets ??= new PluginAssetsService;
    }

    public static function hooks(): PluginHooksService
    {
        return self::$hooks ??= new PluginHooksService;
    }

    public static function gateways(): PluginGatewaysService
    {
        return self::$gateways ??= new PluginGatewaysService;
    }

    public static function extensions(): PluginExtensionsService
    {
        return self::$extensions ??= new PluginExtensionsService;
    }

    public static function productTypes(): PluginProductTypesService
    {
        return self::$productTypes ??= new PluginProductTypesService;
    }

    public static function capabilities(): PluginCapabilitiesService
    {
        return self::$capabilities ??= new PluginCapabilitiesService;
    }

    public static function events(): PluginEventsService
    {
        return self::$events ??= new PluginEventsService;
    }

    public static function license(): PluginLicenseService
    {
        return self::$license ??= app(PluginLicenseService::class);
    }

    /** Versão da API pública de plugins (requires.plugin_api no plugin.json). */
    public static function pluginApiVersion(): int
    {
        return (int) config('plugins.plugin_api', 2);
    }

    /** Versão do core Getfy (requires.getfy no plugin.json). */
    public static function version(): string
    {
        return (string) config('getfy.version', '0.0.0');
    }

    /** @internal */
    public static function resetForTests(): void
    {
        self::$config = null;
        self::$tenant = null;
        self::$products = null;
        self::$orders = null;
        self::$commerce = null;
        self::$assets = null;
        self::$hooks = null;
        self::$gateways = null;
        self::$extensions = null;
        self::$productTypes = null;
        self::$capabilities = null;
        self::$events = null;
        self::$license = null;
    }
}
