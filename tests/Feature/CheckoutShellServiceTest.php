<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Plugins\Commerce\CheckoutShellService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutShellServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_single_shell_product_per_tenant(): void
    {
        $service = app(CheckoutShellService::class);

        $first = $service->productForTenant(1);
        $second = $service->productForTenant(1);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($first->is_active);
        $this->assertSame(Product::TYPE_LINK_PAGAMENTO, $first->type);
        $this->assertSame('__default__', $first->checkout_config['payment_gateways']['pix'] ?? null);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_shell_products_are_isolated_per_tenant(): void
    {
        $service = app(CheckoutShellService::class);

        $tenantOne = $service->productForTenant(1);
        $tenantTwo = $service->productForTenant(2);

        $this->assertNotSame($tenantOne->id, $tenantTwo->id);
        $this->assertDatabaseCount('products', 2);
    }
}
