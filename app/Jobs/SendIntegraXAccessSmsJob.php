<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Services\AccessEmailService;
use App\Services\IntegraX\IntegraXSmsService;
use App\Support\SmsEventConfig;
use App\Support\SmsTemplateRenderer;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendIntegraXAccessSmsJob
{
    use Dispatchable;

    public function __construct(
        public string|int $orderId,
    ) {}

    public function handle(IntegraXSmsService $smsService, AccessEmailService $accessEmailService): void
    {
        try {
            $order = Order::query()->with(['product', 'user'])->find($this->orderId);
            if (! $order || ! $order->product) {
                return;
            }

            $product = $order->product;
            if ($product->type === Product::TYPE_LINK_PAGAMENTO) {
                return;
            }

            $sms = is_array($product->checkout_config['sms'] ?? null)
                ? $product->checkout_config['sms']
                : [];
            $access = is_array($sms['access_delivery'] ?? null) ? $sms['access_delivery'] : [];
            if (! SmsEventConfig::isEnabled($access['enabled'] ?? false)) {
                return;
            }

            $phone = trim((string) ($order->phone ?? ''));
            if ($phone === '') {
                Log::debug('SendIntegraXAccessSmsJob: sem telefone', ['order_id' => $order->id]);

                return;
            }

            $connection = $smsService->connectionForTenant($order->tenant_id ?? $product->tenant_id);
            if (! $connection) {
                return;
            }

            $accessData = $accessEmailService->getAccessDataForOrder($order);
            if (! is_array($accessData)) {
                return;
            }

            $template = trim((string) ($access['body_text'] ?? ''));
            if ($template === '') {
                $template = (string) (Product::defaultSmsConfig()['access_delivery']['body_text'] ?? '');
            }

            $customerName = trim((string) ($order->user?->name ?? ''));
            if ($customerName === '') {
                $customerName = explode('@', (string) ($accessData['email'] ?? ''))[0] ?: 'Cliente';
            }

            $prepared = SmsTemplateRenderer::prepareForSend($template, [
                '{nome_cliente}' => $customerName,
                '{nome_produto}' => (string) ($product->name ?? 'Produto'),
                '{link_acesso}' => (string) ($accessData['link'] ?? ''),
                '{senha}' => (string) ($accessData['password'] ?? ''),
                '{email_cliente}' => (string) ($accessData['email'] ?? $order->email ?? ''),
            ]);

            if (! $prepared['ok']) {
                Log::warning('SendIntegraXAccessSmsJob: mensagem inválida', [
                    'order_id' => $order->id,
                    'length' => $prepared['length'],
                ]);

                return;
            }

            $result = $smsService->sendNow($connection, $phone, $prepared['message']);
            if (! ($result['success'] ?? false)) {
                Log::warning('SendIntegraXAccessSmsJob: falha no envio', [
                    'order_id' => $order->id,
                    'message' => $result['message'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('SendIntegraXAccessSmsJob: exceção', [
                'order_id' => $this->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
