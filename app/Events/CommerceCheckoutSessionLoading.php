<?php

namespace App\Events;

use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disparado antes de renderizar /commerce/checkout/{token}.
 * Listeners podem mutar $data (props Inertia).
 */
class CommerceCheckoutSessionLoading
{
    use Dispatchable, SerializesModels;

    /**
     * @param  \ArrayObject<string, mixed>  $data
     */
    public function __construct(
        public CommerceCheckoutSession $session,
        public Order $order,
        public \ArrayObject $data,
    ) {}
}
