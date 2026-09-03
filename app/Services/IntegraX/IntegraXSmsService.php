<?php

namespace App\Services\IntegraX;

use App\Jobs\IntegraXSendSmsJob;
use App\Models\IntegraxConnection;
use App\Support\SmsPhoneNormalizer;
use App\Support\SmsTemplateRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IntegraXSmsService
{
    private const API_BASE = 'https://sms.aresfun.com/v1/integration';

    public function connectionForTenant(?int $tenantId): ?IntegraxConnection
    {
        $conn = IntegraxConnection::forTenant($tenantId)->first();
        if (! $conn || ! $conn->is_active || ! $conn->isConfigured()) {
            return null;
        }

        return $conn;
    }

    public function queueMessage(?int $tenantId, string $phone, string $message, array $context = []): bool
    {
        $conn = $this->connectionForTenant($tenantId);
        if (! $conn) {
            return false;
        }

        $normalized = SmsPhoneNormalizer::normalizeBrazil($phone);
        if ($normalized === null) {
            Log::debug('IntegraXSmsService: telefone inválido', $context);

            return false;
        }

        $message = trim($message);
        if ($message === '' || SmsTemplateRenderer::exceedsLimit($message)) {
            Log::warning('IntegraXSmsService: mensagem vazia ou excede 160 caracteres', array_merge($context, [
                'length' => SmsTemplateRenderer::length($message),
            ]));

            return false;
        }

        IntegraXSendSmsJob::dispatch($conn->id, $normalized, $message, $context);

        return true;
    }

    /**
     * @return array{success: bool, message?: string, status?: int, body?: mixed}
     */
    public function sendNow(IntegraxConnection $connection, string $phone, string $message): array
    {
        $token = trim((string) $connection->api_token);
        if ($token === '') {
            return ['success' => false, 'message' => 'Token não configurado.'];
        }

        $normalized = SmsPhoneNormalizer::normalizeBrazil($phone);
        if ($normalized === null) {
            return ['success' => false, 'message' => 'Telefone inválido.'];
        }

        $message = trim($message);
        if ($message === '' || SmsTemplateRenderer::exceedsLimit($message)) {
            return ['success' => false, 'message' => 'Mensagem deve ter entre 1 e 160 caracteres.'];
        }

        $payload = [
            'to' => [$normalized],
            'message' => $message,
        ];

        $defaultFrom = trim((string) config('getfy.integrax.default_from', ''));
        if ($defaultFrom !== '') {
            $payload['from'] = $defaultFrom;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->post(self::API_BASE.'/'.rawurlencode($token).'/send-sms', $payload);

            if ($response->successful()) {
                $connection->update([
                    'last_tested_at' => now(),
                    'last_error' => null,
                ]);

                return [
                    'success' => true,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ];
            }

            $error = $response->json('message') ?? $response->body();
            $connection->update(['last_error' => is_string($error) ? $error : json_encode($error)]);

            return [
                'success' => false,
                'message' => is_string($error) ? $error : 'Falha ao enviar SMS.',
                'status' => $response->status(),
                'body' => $response->json(),
            ];
        } catch (\Throwable $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            Log::warning('IntegraXSmsService: exceção ao enviar SMS', ['message' => $e->getMessage()]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
