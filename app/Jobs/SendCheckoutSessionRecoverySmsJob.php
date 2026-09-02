<?php

namespace App\Jobs;

use App\Models\CheckoutSession;
use App\Models\Product;
use App\Services\IntegraX\IntegraXSmsService;
use App\Support\SmsTemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCheckoutSessionRecoverySmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $checkoutSessionId,
        public int $stageIndex
    ) {}

    public function handle(IntegraXSmsService $smsService): void
    {
        $session = CheckoutSession::with('product')->find($this->checkoutSessionId);
        if (! $session || $session->order_id !== null) {
            return;
        }

        if (! in_array($session->step, [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED], true)) {
            return;
        }

        $phone = trim((string) ($session->phone ?? ''));
        if ($phone === '') {
            return;
        }

        $product = $session->product;
        if (! $product) {
            return;
        }

        $recovery = $this->smsRecoveryConfig($product);
        if ($recovery === null) {
            return;
        }

        $stages = $recovery['stages'];
        $stage = $stages[$this->stageIndex] ?? null;
        if (! is_array($stage)) {
            return;
        }

        $template = trim((string) ($stage['body_text'] ?? ''));
        if ($template === '') {
            return;
        }

        $checkoutUrl = $this->buildCheckoutUrlForSession($session, $product);
        $email = (string) ($session->email ?? '');
        $name = trim((string) ($session->name ?? ''));
        $customerName = $name !== '' ? $name : ($email !== '' ? explode('@', $email)[0] : 'Cliente');

        $prepared = SmsTemplateRenderer::prepareForSend($template, [
            '{nome_cliente}' => $customerName,
            '{nome_produto}' => (string) ($product->name ?? 'Produto'),
            '{valor}' => 'R$ '.number_format((float) ($product->price ?? 0), 2, ',', '.'),
            '{link_checkout}' => $checkoutUrl,
        ]);

        if (! $prepared['ok']) {
            Log::warning('SendCheckoutSessionRecoverySmsJob: mensagem inválida', [
                'checkout_session_id' => $session->id,
                'length' => $prepared['length'],
            ]);

            return;
        }

        $smsService->queueMessage(
            $session->tenant_id ?? $product->tenant_id,
            $phone,
            $prepared['message'],
            ['event' => 'cart_recovery_session', 'checkout_session_id' => $session->id, 'stage' => $this->stageIndex]
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

    private function buildCheckoutUrlForSession(CheckoutSession $session, Product $product): string
    {
        $slug = trim((string) ($session->checkout_slug ?: $product->checkout_slug));
        $base = url('/c/'.$slug);
        $params = array_filter([
            'email' => $session->email,
            'name' => $session->name,
            'phone' => $session->phone,
        ], fn ($v) => is_string($v) && trim($v) !== '');

        return $params ? ($base.'?'.http_build_query($params)) : $base;
    }
}
