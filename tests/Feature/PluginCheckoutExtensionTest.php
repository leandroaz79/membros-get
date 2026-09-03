<?php

namespace Tests\Feature;

use App\Events\CheckoutBeforeProcess;
use App\Models\Product;
use App\Plugins\PluginCheckoutExtensionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginCheckoutExtensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_before_process_supports_amount_adjustment_and_metadata(): void
    {
        $product = $this->createTestProduct();
        $event = new CheckoutBeforeProcess($product, ['email' => 'a@b.com'], null, []);
        $event->amountAdjustment = 15.5;
        $event->orderMetadata = ['shipping_amount' => 15.5, 'plugin_checkout' => 'test'];

        $this->assertSame(15.5, $event->amountAdjustment);
        $this->assertSame(['shipping_amount' => 15.5, 'plugin_checkout' => 'test'], $event->orderMetadata);
    }

    public function test_extension_registry_decodes_json_payload(): void
    {
        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'plugin_checkout_data' => json_encode(['getfy-loja-fisica' => ['shipping_cep' => '01310100']]),
        ]);

        $decoded = PluginCheckoutExtensionRegistry::decodeCheckoutDataFromRequest($request);
        $this->assertSame('01310100', $decoded['getfy-loja-fisica']['shipping_cep'] ?? null);
    }
}
