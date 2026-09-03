<?php

namespace App\Listeners;

use App\Events\PixGenerated;
use App\Jobs\SendIntegraXPixSmsJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Síncrono: enfileira envio após a resposta HTTP (dispatchAfterResponse → dispatchSync).
 * Não usar ShouldQueue — com redis sem worker o SMS nunca rodaria.
 */
class SendIntegraXSmsOnPixGenerated
{
    public function handle(PixGenerated $event): void
    {
        try {
            SendIntegraXPixSmsJob::dispatchAfterResponse(
                $event->order->id,
                $event->pixData
            );
        } catch (Throwable $e) {
            Log::warning('SendIntegraXSmsOnPixGenerated: falha ao agendar SMS (PIX não afetado)', [
                'order_id' => $event->order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
