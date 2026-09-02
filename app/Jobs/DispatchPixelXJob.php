<?php

namespace App\Jobs;

use App\Models\PixelXIntegration;
use App\Models\PixelXIntegrationLog;
use App\Support\PixelXPayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DispatchPixelXJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $integrationId,
        public string $eventSlug,
        public array $webhookPayload,
    ) {}

    public function handle(): void
    {
        $integration = PixelXIntegration::find($this->integrationId);

        if (! $integration || ! $integration->is_active) {
            return;
        }

        // Obter token descriptografado — pode lançar exceção se APP_KEY mudou
        $token = null;
        try {
            $token = $integration->token;
        } catch (\Throwable $e) {
            PixelXIntegrationLog::create([
                'pixel_x_integration_id' => $integration->id,
                'event'                  => $this->eventSlug,
                'event_label'            => null,
                'request_payload'        => [],
                'response_status'        => null,
                'response_body'          => null,
                'success'                => false,
                'error_message'          => 'Token inválido: '.$e->getMessage(),
                'source'                 => 'job',
            ]);

            return;
        }

        // Construir payload completo no formato Pixel X (token já incluído no body)
        $body = PixelXPayloadBuilder::build($this->eventSlug, $this->webhookPayload, $token ?? '');

        // SEGURANÇA: remover token antes de gravar em log — T-01-02-02
        $logPayload = array_diff_key($body, ['token' => true]);

        try {
            // POST com token no body JSON (NÃO no header Authorization: Bearer)
            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($integration->url, $body);

            $responseStatus = $response->status();
            $responseBody   = $response->body();
            $success        = $response->successful();

            PixelXIntegrationLog::create([
                'pixel_x_integration_id' => $integration->id,
                'event'                  => $this->eventSlug,
                'event_label'            => null,
                'request_payload'        => $logPayload,
                'response_status'        => $responseStatus,
                'response_body'          => strlen($responseBody) > 2000
                    ? substr($responseBody, 0, 2000).'…'
                    : $responseBody,
                'success'                => $success,
                'error_message'          => $success ? null : 'HTTP '.$responseStatus,
                'source'                 => 'job',
            ]);

            if (! $success && $this->job) {
                $this->release($this->backoff);
            }
        } catch (\Throwable $e) {
            PixelXIntegrationLog::create([
                'pixel_x_integration_id' => $integration->id,
                'event'                  => $this->eventSlug,
                'event_label'            => null,
                'request_payload'        => $logPayload,
                'response_status'        => null,
                'response_body'          => null,
                'success'                => false,
                'error_message'          => $e->getMessage(),
                'source'                 => 'job',
            ]);

            if ($this->job) {
                $this->release($this->backoff);
            }
        }
    }
}
