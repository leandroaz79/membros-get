<?php

namespace App\Plugins\Commerce;

use App\Models\Product;

/**
 * Produto interno por tenant para pedidos iniciados por plugins (lojas customizadas).
 */
class CheckoutShellService
{
    public const SHELL_SLUG_PREFIX = '_plugin_checkout_shell';

    public function productForTenant(int $tenantId): Product
    {
        $slug = self::SHELL_SLUG_PREFIX.'-'.$tenantId;

        $existing = Product::forTenant($tenantId)->where('slug', $slug)->first();
        if ($existing) {
            return $existing;
        }

        return Product::create([
            'tenant_id' => $tenantId,
            'name' => 'Checkout Plugin',
            'slug' => $slug,
            'description' => 'Produto interno para checkout de plugins. Não exibir na vitrine.',
            'type' => Product::TYPE_LINK_PAGAMENTO,
            'billing_type' => Product::BILLING_ONE_TIME,
            'price' => 0,
            'currency' => 'BRL',
            'is_active' => false,
            'checkout_config' => [
                'payment_gateways' => [
                    'pix' => '__default__',
                    'card' => '__default__',
                    'boleto' => '__default__',
                ],
            ],
        ]);
    }

    public function isShellProduct(Product $product): bool
    {
        return str_starts_with((string) $product->slug, self::SHELL_SLUG_PREFIX);
    }
}
