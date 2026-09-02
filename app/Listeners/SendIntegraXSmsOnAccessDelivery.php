<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Jobs\SendIntegraXAccessSmsJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * @see SendIntegraXSmsOnPixGenerated
 */
class SendIntegraXSmsOnAccessDelivery
{
    public function handle(OrderCompleted $event): void
    {
        try {
            SendIntegraXAccessSmsJob::dispatchAfterResponse($event->order->id);
        } catch (Throwable $e) {
            Log::warning('SendIntegraXSmsOnAccessDelivery: falha ao agendar SMS', [
                'order_id' => $event->order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
