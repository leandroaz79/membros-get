<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Plugins\PluginProductTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PluginProductTypeRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PluginProductTypeRegistry::register('test-plugin', 'produto_fisico', [
            'maps_to' => Product::TYPE_LINK_PAGAMENTO,
            'label' => 'Produto físico',
            'description' => 'Teste',
            'available' => true,
            'icon' => 'truck',
        ]);
    }

    public function test_virtual_type_maps_to_core_on_store(): void
    {
        $user = User::factory()->create(['role' => 'infoprodutor', 'tenant_id' => 1]);

        $response = $this->actingAs($user)->post('/produtos', [
            'name' => 'Camiseta',
            'type' => 'produto_fisico',
            'billing_type' => 'one_time',
            'price' => 99.9,
            'currency' => 'BRL',
            'is_active' => true,
        ]);

        $response->assertRedirect(route('produtos.index'));
        $product = Product::query()->where('name', 'Camiseta')->first();
        $this->assertNotNull($product);
        $this->assertSame(Product::TYPE_LINK_PAGAMENTO, $product->type);
        $marker = $product->checkout_config['_plugin_product'] ?? null;
        $this->assertIsArray($marker);
        $this->assertSame('produto_fisico', $marker['virtual_type'] ?? null);
        $this->assertSame('test-plugin', $marker['slug'] ?? null);
    }
}
