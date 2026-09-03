<?php

namespace App\Plugins;

use App\Models\Order;
use App\Plugins\Commerce\CommerceCheckoutContextRegistry;

/**
 * DTO estável de pedido para listeners de plugins (envio, ERP, etc.).
 */
class PluginOrderContext
{
    /**
     * @param  array<int, array{product_id?: int|string, name?: string, quantity?: int, amount?: float}>  $lineItems
     * @param  array<string, mixed>  $shippingAddress
     */
    public function __construct(
        public int $orderId,
        public int $tenantId,
        public string $status,
        public ?string $paymentMethod,
        public float $amount,
        public string $currency,
        public ?string $customerEmail,
        public ?string $customerName,
        public array $shippingAddress,
        public array $lineItems,
        public array $metadata = [],
    ) {}

    public static function fromOrder(Order $order): self
    {
        $order->loadMissing(['product']);

        $lineItems = [];
        $order->loadMissing(['orderItems.product']);
        foreach ($order->orderItems ?? [] as $item) {
            $lineItems[] = [
                'product_id' => $item->product_id ?? null,
                'name' => $item->product?->name ?? null,
                'quantity' => 1,
                'amount' => (float) ($item->amount ?? 0),
            ];
        }
        $registryItems = CommerceCheckoutContextRegistry::resolveLineItems($order);
        if ($registryItems !== null && $registryItems !== []) {
            $lineItems = $registryItems;
        } elseif ($lineItems === [] && $order->product) {
            $lineItems[] = [
                'product_id' => $order->product_id,
                'name' => $order->product->name,
                'quantity' => 1,
                'amount' => (float) ($order->amount ?? 0),
            ];
        }

        $meta = is_array($order->metadata) ? $order->metadata : (json_decode((string) $order->metadata, true) ?: []);

        $shipping = [];
        if (is_array($meta['shipping_address'] ?? null)) {
            $shipping = $meta['shipping_address'];
        } else {
            foreach (['address_zipcode', 'address_street', 'address_number', 'address_city', 'address_state', 'address_neighborhood'] as $key) {
                if (! empty($meta[$key])) {
                    $shipping[$key] = $meta[$key];
                }
            }
        }

        $customerName = is_array($meta) ? (string) ($meta['name'] ?? $meta['customer_name'] ?? '') : '';
        if ($customerName === '' && $order->relationLoaded('user') && $order->user) {
            $customerName = (string) ($order->user->name ?? '');
        }

        return new self(
            orderId: (int) $order->id,
            tenantId: (int) $order->tenant_id,
            status: (string) ($order->status ?? ''),
            paymentMethod: $order->gateway !== null ? (string) $order->gateway : null,
            amount: (float) ($order->amount ?? 0),
            currency: (string) ($order->currency ?? 'BRL'),
            customerEmail: $order->email !== null ? (string) $order->email : null,
            customerName: $customerName !== '' ? $customerName : null,
            shippingAddress: $shipping,
            lineItems: $lineItems,
            metadata: is_array($meta) ? $meta : [],
        );
    }
}
