<?php

use App\PluginSdk\Getfy;
use Plugins\GetfyExampleGateway\ExampleGatewayDriver;
use Plugins\GetfyExampleGateway\ExampleGatewayWebhookHandler;

/**
 * Registra o gateway de exemplo. Copie este plugin e troque API/credenciais.
 *
 * @param  \Illuminate\Contracts\Foundation\Application  $app
 * @param  \Illuminate\Contracts\Events\Dispatcher  $events
 */
return function ($app, $events): void {
    Getfy::gateways()->register([
        'slug' => 'example-gateway',
        'name' => 'Example Gateway',
        'image' => 'plugin:getfy-example-gateway/logo.svg',
        'methods' => ['pix', 'card', 'boleto'],
        'scope' => 'national',
        'country' => 'br',
        'country_name' => 'Brasil',
        'signup_url' => 'https://getfy.org/developers',
        'driver' => ExampleGatewayDriver::class,
        'webhook_handler' => ExampleGatewayWebhookHandler::class,
        'credential_keys' => [
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
            ['key' => 'webhook_secret', 'label' => 'Webhook Secret', 'type' => 'password'],
            ['key' => 'sandbox', 'label' => 'Sandbox', 'type' => 'boolean'],
            ['key' => 'publishable_key', 'label' => 'Publishable Key (front)', 'type' => 'text'],
        ],
        'checkout_payload_keys' => ['publishable_key'],
        'card_mode' => 'token',
    ]);
};
