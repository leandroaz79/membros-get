<?php

namespace App\Events;

use App\Models\Order;
use App\Plugins\PluginOrderContext;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public string $previousStatus,
        public string $newStatus,
        public ?PluginOrderContext $context = null,
    ) {
        $this->context ??= PluginOrderContext::fromOrder($order);
    }
}
