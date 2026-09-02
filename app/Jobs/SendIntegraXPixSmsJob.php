<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Services\IntegraX\IntegraXSmsService;
use App\Support\IntegraXPixLinkResolver;
use App\Support\SmsEventConfig;
use App\Support\SmsTemplateRenderer;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendIntegraXPixSmsJob
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $pixData
     */
    public function __construct(
        public string|int $orderId,
        public array $pixData = [],
    ) {}

    public function handle(IntegraXSmsService $smsService): void
    {
        try {
            $order = Order::query()->with(['product', 'user'])->find($this->orderId);
            if (! $order || ! $order->product) {
                return;
            }

            $product = $order->product;
            $sms = is_array($product->checkout_config['sms'] ?? null)
                ? $product->checkout_config['sms']
                : [];
            $pixCfg = is_array($sms['pix_generated'] ?? null) ? $sms['pix_generated'] : [];
            if (! SmsEventConfig::isEnabled($pixCfg['enabled'] ?? false)) {
                return;
            }

            $phone = trim((string) ($order->phone ?? ''));
            if ($phone === '') {
                Log::debug('SendIntegraXPixSmsJob: sem telefone', ['order_id' => $order->id]);

                return;
            }

            if (! $smsService->connectionForTenant($order->tenant_id ?? $product->tenant_id)) {
                Log::debug('SendIntegraXPixSmsJob: IntegraX inativa ou sem token', [
                    'order_id' => $order->id,
                    'tenant_id' => $order->tenant_id ?? $product->tenant_id,
                ]);

                return;
            }

            $linkPix = IntegraXPixLinkResolver::urlForOrder($order, $this->pixData);
            if ($linkPix === null) {
                Log::warning('SendIntegraXPixSmsJob: link PIX indisponível', [
                    'order_id' => $order->id,
                    'pix_data_keys' => array_keys($this->pixData),
                ]);

                return;
            }

            $template = trim((string) ($pixCfg['body_text'] ?? ''));
            if ($template === '') {
                $template = (string) (Product::defaultSmsConfig()['pix_generated']['body_text'] ?? '');
            }

            $customerName = trim((string) ($order->user?->name ?? ''));
            if ($customerName === '') {
                $customerName = 'Cliente';
            }

            $variables = [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => (string) ($product->name ?? 'Produto'),
                '{valor}' => 'R$ '.number_format((float) $order->amount, 2, ',', '.'),
                '{link_pix}' => $linkPix,
            ];

            $prepared = SmsTemplateRenderer::prepareForSend($template, $variables);
            if (! $prepared['ok']) {
                $prepared = SmsTemplateRenderer::prepareForSend('Pague seu PIX: {link_pix}', [
                    '{link_pix}' => $linkPix,
                ]);
            }

            if (! $prepared['ok']) {
                Log::warning('SendIntegraXPixSmsJob: mensagem excede 160 caracteres', [
                    'order_id' => $order->id,
                    'length' => $prepared['length'],
                ]);

                return;
            }

            $connection = $smsService->connectionForTenant($order->tenant_id ?? $product->tenant_id);
            if (! $connection) {
                return;
            }

            $result = $smsService->sendNow($connection, $phone, $prepared['message']);
            if (! ($result['success'] ?? false)) {
                Log::warning('SendIntegraXPixSmsJob: falha no envio', [
                    'order_id' => $order->id,
                    'message' => $result['message'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('SendIntegraXPixSmsJob: exceção (PIX não afetado)', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
