<?php

namespace App\Support;

class PixelXPayloadBuilder
{
    /**
     * Mapeamento dos 10 eventos Getfy para os 7 status nativos da Pixel X.
     *
     * @var array<string, string>
     */
    private const EVENT_STATUS_MAP = [
        'pedido_pendente'     => 'waiting_payment',
        'pedido_pago'         => 'approved',
        'pagamento_recusado'  => 'waiting_payment',
        'reembolso'           => 'refund',
        'pix_gerado'          => 'billet_pix_generate',
        'boleto_gerado'       => 'billet_pix_generate',
        'carrinho_abandonado' => 'abandoned_cart',
        'assinatura_criada'   => 'subscribe',
        'assinatura_renovada' => 'subscribe',
        'assinatura_cancelada' => 'canceled',
    ];

    /**
     * Constrói o payload no formato proprietário Pixel X.
     *
     * @param  string               $eventSlug       Slug do evento Getfy (ex: 'pedido_pago')
     * @param  array<string, mixed> $webhookPayload  Payload já montado pelo WebhookPayloadBuilder
     * @param  string               $token           Token de integração da Pixel X
     * @return array<string, mixed>
     */
    public static function build(string $eventSlug, array $webhookPayload, string $token): array
    {
        $customer  = $webhookPayload['customer'] ?? [];
        $product   = $webhookPayload['product'] ?? [];
        $payment   = $webhookPayload['payment'] ?? [];
        $tracking  = $webhookPayload['tracking'] ?? [];
        $order     = $webhookPayload['order'] ?? [];

        $paymentMethod = self::mapPaymentMethod((string) ($payment['method'] ?? $webhookPayload['paymentMethod'] ?? 'pix'));
        $currency      = (string) ($order['currency'] ?? 'BRL');
        $installments  = (int) ($webhookPayload['installments'] ?? $order['metadata']['installments'] ?? 1);
        $productValue  = isset($order['amount'])
            ? number_format((float) $order['amount'], 2, '.', '')
            : (isset($product['price']) ? number_format((float) $product['price'], 2, '.', '') : '0.00');

        $payload = [
            'token' => $token,
            'event' => [
                'status' => self::EVENT_STATUS_MAP[$eventSlug] ?? 'waiting_payment',
                'date'   => now()->format('Y-m-d H:i:s'),
            ],
            'product' => [
                'id'             => (string) ($product['id'] ?? ''),
                'name'           => (string) ($product['name'] ?? ''),
                'value'          => $productValue,
                'currency'       => $currency,
                'payment_method' => $paymentMethod,
                'installments'   => $installments,
            ],
            'lead' => [
                'name'     => (string) ($customer['name'] ?? ''),
                'email'    => (string) ($customer['email'] ?? ''),
                'phone'    => (string) ($customer['phone'] ?? ''),
                'document' => (string) ($customer['cpf'] ?? ''),
                'location' => [
                    'country' => 'BR',
                ],
            ],
            'device' => [
                'ip'    => (string) (request()->ip() ?? ''),
                'agent' => (string) (request()->userAgent() ?? ''),
            ],
            'parameter' => [
                'utm_source' => (string) ($tracking['utm_source'] ?? ''),
                'utm_medium' => (string) ($tracking['utm_medium'] ?? ''),
                'fbclid'     => (string) ($tracking['fbclid'] ?? ''),
                'fbp'        => (string) ($tracking['fbp'] ?? ''),
                'gclid'      => (string) ($tracking['gclid'] ?? ''),
            ],
        ];

        // recurrence: apenas para eventos de assinatura
        if (str_starts_with($eventSlug, 'assinatura_')) {
            $recurrenceNumber = match ($eventSlug) {
                'assinatura_criada'   => 1,
                'assinatura_cancelada' => 0,
                default => (int) ($webhookPayload['subscription']['id'] ?? 1), // assinatura_renovada
            };
            $payload['recurrence'] = ['number' => $recurrenceNumber];
        }

        return $payload;
    }

    /**
     * Gera um payload de exemplo para testes.
     *
     * @param  string $eventSlug Slug do evento Getfy
     * @param  string $token     Token de integração (apenas para montar estrutura — não persiste)
     * @return array<string, mixed>
     */
    public static function samplePayload(string $eventSlug, string $token): array
    {
        $sampleWebhookPayload = WebhookPayloadBuilder::sampleTestPayload($eventSlug);

        return self::build($eventSlug, $sampleWebhookPayload, $token);
    }

    /**
     * Mapeia método de pagamento do Getfy para nomenclatura aceita pela Pixel X.
     */
    private static function mapPaymentMethod(string $method): string
    {
        return match (strtolower($method)) {
            'pix', 'pix_auto'         => 'pix',
            'card', 'credit_card'     => 'credit_card',
            'boleto'                  => 'boleto',
            default                   => $method,
        };
    }
}
