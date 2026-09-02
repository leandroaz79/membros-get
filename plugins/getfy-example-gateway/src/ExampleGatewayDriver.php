<?php

namespace Plugins\GetfyExampleGateway;

use App\PluginSdk\Contracts\GatewayDriver;
use Illuminate\Support\Str;

/**
 * Driver de referência. Substitua as chamadas HTTP pela API do seu provedor.
 */
class ExampleGatewayDriver implements GatewayDriver
{
    public function testConnection(array $credentials): bool
    {
        return trim((string) ($credentials['api_key'] ?? '')) !== '';
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        $tx = 'ex_pix_'.Str::lower(Str::random(16));
        $copyPaste = '00020126EXAMPLE'.preg_replace('/\D/', '', (string) round($amount * 100)).$tx;

        // Em produção: chame a API do gateway com $credentials['api_key'] e $postbackUrl.
        return [
            'transaction_id' => $tx,
            'qrcode' => 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120"><rect width="120" height="120" fill="#111"/><text x="60" y="64" fill="#74d909" text-anchor="middle" font-size="12">PIX DEMO</text></svg>'),
            'copy_paste' => $copyPaste,
            'raw' => [
                'sandbox' => (bool) ($credentials['sandbox'] ?? true),
                'external_id' => $externalId,
                'amount' => $amount,
                'consumer' => $consumer,
            ],
        ];
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        if ($transactionId === '' || trim((string) ($credentials['api_key'] ?? '')) === '') {
            return null;
        }

        // Demo: trate IDs terminados em "paid" como pagos.
        if (str_ends_with(strtolower($transactionId), 'paid')) {
            return 'paid';
        }

        return 'pending';
    }

    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $token = (string) ($card['payment_token'] ?? '');
        if ($token === '') {
            throw new \InvalidArgumentException('payment_token ausente.');
        }

        return [
            'transaction_id' => 'ex_card_'.Str::lower(Str::random(12)),
            'status' => 'paid',
            'raw' => [
                'external_id' => $externalId,
                'amount' => $amount,
                'card_mask' => $card['card_mask'] ?? null,
            ],
        ];
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        $tx = 'ex_bol_'.Str::lower(Str::random(12));

        return [
            'transaction_id' => $tx,
            'amount' => $amount,
            'expire_at' => now()->addDays(3)->toIso8601String(),
            'barcode' => '23793.38128 60000.000003 00000.000400 1 843400000'.str_pad((string) (int) round($amount * 100), 10, '0', STR_PAD_LEFT),
            'pdf_url' => 'https://getfy.org',
            'raw' => [
                'external_id' => $externalId,
                'notification_url' => $notificationUrl,
                'consumer' => $consumer,
            ],
        ];
    }
}
