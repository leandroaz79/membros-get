<?php

namespace Tests\Feature;

use App\Gateways\GatewayRegistry;
use App\Models\Plugin;
use App\PluginSdk\Getfy;
use App\Plugins\PluginClassAutoloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ExampleGatewayPluginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $path = base_path('plugins/getfy-example-gateway');
        if (! is_dir($path)) {
            $this->markTestSkipped('getfy-example-gateway ausente.');
        }
    }

    public function test_validate_command_passes(): void
    {
        $this->artisan('plugin:validate', ['slug' => 'getfy-example-gateway'])
            ->assertSuccessful();
    }

    public function test_bootstrap_registers_gateway_via_sdk(): void
    {
        Plugin::create([
            'slug' => 'getfy-example-gateway',
            'name' => 'Getfy Example Gateway',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);

        PluginClassAutoloader::refreshPrefixes();
        $bootstrap = require base_path('plugins/getfy-example-gateway/bootstrap.php');
        $bootstrap($this->app, $this->app['events']);

        $gw = GatewayRegistry::get('example-gateway');
        $this->assertNotNull($gw);
        $this->assertSame(Getfy::gateways()->get('example-gateway')['slug'], 'example-gateway');
        $this->assertContains('pix', $gw['methods']);

        $driver = GatewayRegistry::driver('example-gateway');
        $this->assertNotNull($driver);
        $pix = $driver->createPixPayment(
            ['api_key' => 'test'],
            10.5,
            ['name' => 'A', 'document' => '000', 'email' => 'a@b.c'],
            'ext-1',
            'https://example.test/hook'
        );
        $this->assertArrayHasKey('transaction_id', $pix);
        $this->assertArrayHasKey('copy_paste', $pix);
    }

    public function test_webhook_handler_accepts_payload(): void
    {
        Plugin::create([
            'slug' => 'getfy-example-gateway',
            'name' => 'Getfy Example Gateway',
            'version' => '1.0.0',
            'is_enabled' => true,
        ]);
        PluginClassAutoloader::register();
        PluginClassAutoloader::refreshPrefixes();
        $this->assertTrue(class_exists(\Plugins\GetfyExampleGateway\ExampleGatewayWebhookHandler::class));
        $handler = new \Plugins\GetfyExampleGateway\ExampleGatewayWebhookHandler;
        $response = $handler->handle(Request::create('/webhooks/gateways/example-gateway', 'POST', [
            'transaction_id' => 'missing-tx',
            'status' => 'paid',
        ]), 'example-gateway');

        $this->assertSame(200, $response->getStatusCode());
    }
}
