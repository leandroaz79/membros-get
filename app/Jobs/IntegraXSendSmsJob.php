<?php

namespace App\Jobs;

use App\Models\IntegraxConnection;
use App\Services\IntegraX\IntegraXSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IntegraXSendSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public int $connectionId,
        public string $phone,
        public string $message,
        public array $context = []
    ) {}

    public function handle(IntegraXSmsService $service): void
    {
        $connection = IntegraxConnection::find($this->connectionId);
        if (! $connection || ! $connection->is_active) {
            return;
        }

        $result = $service->sendNow($connection, $this->phone, $this->message);
        if (! ($result['success'] ?? false)) {
            Log::warning('IntegraXSendSmsJob: falha no envio', array_merge($this->context, [
                'connection_id' => $this->connectionId,
                'message' => $result['message'] ?? null,
            ]));
        }
    }
}
