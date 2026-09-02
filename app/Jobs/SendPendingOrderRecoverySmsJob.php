<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Services\IntegraX\IntegraXSmsService;
use App\Support\SmsTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPendingOrderRecoverySmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $orderId,
        public int $stageIndex
    ) {}

    public function handle(IntegraXSmsService $smsService): void
    {
        $order = Order::with(['product', 'user'])->find($this->orderId);
        if (! $order || $order->status !== 'pending') {
            return;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        $method = strtolower((string) ($meta['checkout_payment_method'] ?? ''));
        if (! in_array($method, ['pix', 'boleto', 'pix_auto'], true)) {
            return;
        }

        $phone = trim((string) ($order->phone ?? ''));
        if ($phone === '') {
            return;
        }

        $product = $order->product;
        if (! $product) {
            return;
        }

        $recovery = $this->smsRecoveryConfig($product);
        if ($recovery === null) {
            return;
        }

        $stage = $recovery['stages'][$this->stageIndex] ?? null;
        if (! is_array($stage)) {
            return;
        }

        $template = trim((string) ($stage['body_text'] ?? ''));
        if ($template === '') {
            return;
        }

        $checkoutUrl = $this->buildCheckoutUrlForOrder($order, $product);
        $email = (string) ($order->email ?? $order->user?->email ?? '');
        $customerName = trim((string) ($order->user?->name ?? ''));
        if ($customerName === '') {
            $customerName = $email !== '' ? explode('@', $email)[0] : 'Cliente';
        }

        $prepared = SmsTemplateRenderer::prepareForSend($template, [
            '{nome_cliente}' => $customerName,
            '{nome_produto}' => (string) ($product->name ?? 'Produto'),
            '{valor}' => 'R$ '.number_format((float) ($order->amount ?? 0), 2, ',', '.'),
            '{link_checkout}' => $checkoutUrl,
        ]);

        if (! $prepared['ok']) {
            Log::warning('SendPendingOrderRecoverySmsJob: mensagem inválida', [
                'order_id' => $order->id,
                'length' => $prepared['length'],
            ]);

            return;
        }

        $smsService->queueMessage(
            $order->tenant_id ?? $product->tenant_id,
            $phone,
            $prepared['message'],
            ['event' => 'cart_recovery_order', 'order_id' => $order->id, 'stage' => $this->stageIndex]
        );
    }

    /**
     * @return array{stages: array<int, array<string, mixed>>}|null
     */
    private function smsRecoveryConfig(Product $product): ?array
    {
        $sms = is_array($product->checkout_config['sms'] ?? null) ? $product->checkout_config['sms'] : [];
        $recovery = is_array($sms['cart_recovery'] ?? null) ? $sms['cart_recovery'] : [];
        if (empty($recovery['enabled'])) {
            return null;
        }

        $stages = array_values(array_filter(
            is_array($recovery['stages'] ?? null) ? $recovery['stages'] : [],
            fn ($s) => is_array($s)
        ));

        if ($stages === []) {
            return null;
        }

        return ['stages' => $stages];
    }

    private function buildCheckoutUrlForOrder(Order $order, Product $product): string
    {
        $slug = trim((string) $order->getCheckoutSlug());
        if ($slug === '') {
            $slug = trim((string) ($product->checkout_slug ?? ''));
        }

        $base = url('/c/'.$slug);
        $params = array_filter([
            'email' => (string) ($order->email ?? ''),
            'name' => (string) ($order->user?->name ?? ''),
            'phone' => (string) ($order->phone ?? ''),
            'cpf' => (string) ($order->cpf ?? ''),
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return $params ? ($base.'?'.http_build_query($params)) : $base;
    }
}
