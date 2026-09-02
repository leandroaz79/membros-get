<?php

namespace App\Events\Commerce;

use App\Models\CommerceCart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Carrinho de loja (commerce_carts) abandonado — distinto de CartAbandoned (checkout por produto).
 */
class CommerceCartAbandoned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CommerceCart $cart,
    ) {}
}
