<?php

namespace App\Events\Commerce;

use App\Models\CommerceCart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CartUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public CommerceCart $cart,
    ) {}
}
