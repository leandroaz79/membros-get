<?php

namespace App\Events\Commerce;

use App\Models\CommerceCart;
use App\Models\CommerceCartLine;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartLineAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CommerceCart $cart,
        public CommerceCartLine $line,
    ) {}
}
