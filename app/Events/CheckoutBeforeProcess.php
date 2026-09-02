<?php

namespace App\Events;

use App\Models\CommerceCart;
use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CheckoutBeforeProcess
{
    use Dispatchable, SerializesModels;

    /**
     * If set by a listener, the checkout will be aborted with this message.
     */
    public ?string $abort = null;

    /** Ajuste de valor (ex.: frete) somado ao total do pedido. */
    public float $amountAdjustment = 0.0;

    /** Metadata extra gravada no pedido (merge com metadata existente). */
    public array $orderMetadata = [];

    public function __construct(
        public Product $product,
        public array $validated,
        public ?CommerceCart $cart = null,
        public array $pluginCheckoutData = [],
    ) {}
}
