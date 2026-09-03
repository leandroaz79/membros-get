<?php

namespace App\PluginSdk;

use App\Gateways\GatewayRegistry;
use App\PluginSdk\Contracts\GatewayDriver;

/**
 * Registro e consulta de gateways de pagamento via plugins.
 */
class PluginGatewaysService
{
    /**
     * @param  array{
     *   slug: string,
     *   name: string,
     *   image?: string,
     *   methods: array<int, string>,
     *   scope?: string,
     *   country?: string,
     *   country_name?: string,
     *   signup_url?: string,
     *   driver: class-string<GatewayDriver>|class-string,
     *   credential_keys?: array<int, array<string, mixed>>,
     *   checkout_payload_keys?: array<int, string>,
     *   webhook_handler?: class-string|callable,
     *   card_mode?: string
     * }  $gateway
     */
    public function register(array $gateway): void
    {
        GatewayRegistry::register($gateway);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return GatewayRegistry::all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $slug): ?array
    {
        return GatewayRegistry::get($slug);
    }

    public function driver(string $slug): ?\App\Gateways\Contracts\GatewayDriver
    {
        return GatewayRegistry::driver($slug);
    }

    public function resolveImageUrl(?string $image): ?string
    {
        return GatewayRegistry::resolveImageUrl($image);
    }
}
