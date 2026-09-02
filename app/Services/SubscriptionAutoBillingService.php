<?php

namespace App\Services;

use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Mail\SubscriptionReminderMail;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\CajuPaySubscriptionService;
use App\Support\CheckoutCurrencyCatalog;
use App\Support\FakeConsumerData;
use App\Support\PixCheckoutDisplay;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Gera cobrança automática de renovação (PIX / PIX automático) sem o cliente
 * precisar abrir a página resumida de renovação.
 */
class SubscriptionAutoBillingService
{
    public const PENDING_PIX_REUSE_HOURS = 20;

    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly PaymentService $paymentService,
        private readonly TenantMailConfigService $mailConfig,
    ) {}

    /**
     * Garante um pagamento de renovação pendente. Preferências:
     * 1) PIX automático (gateway_subscription_id)
     * 2) Novo PIX avulso + e-mail com copia-e-cola
     *
     * Cartão off-session não é suportado (tokens não são persistidos no checkout).
     *
     * @return array{type: string, order?: Order, copy_paste?: ?string, qrcode?: ?string, emailed?: bool}|null
     */
    public function ensureRenewalPayment(Subscription $subscription, bool $sendEmail = true): ?array
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);

        if (! $this->lifecycle->canRenew($subscription)) {
            return null;
        }

        if ($this->lifecycle->isLifetime($subscription)) {
            return null;
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;

        if (! $product || ! $plan || ! $user) {
            return null;
        }

        // Reutiliza pedido PIX pendente recente
        $existing = $this->findReusablePendingRenewalOrder($subscription);
        if ($existing) {
            $pix = $this->pixDataFromOrder($existing);
            if ($sendEmail && $pix && $this->shouldEmailPix($subscription)) {
                $this->sendPixRenewalEmail($subscription, $existing, $pix);
            }

            return array_merge(['type' => 'pix_existing', 'order' => $existing], $pix ?? []);
        }

        // CajuPay PIX Automático cobra as parcelas seguintes sozinho após o mandato.
        if ($this->isCajuPayManagedSubscription($subscription)) {
            return null;
        }

        // PIX automático Efí/Pushin (id recorrência já vinculado)
        if ($subscription->gateway_subscription_id) {
            $scheduled = $this->trySchedulePixAuto($subscription);
            if ($scheduled !== null) {
                if ($sendEmail) {
                    $this->sendPixAutoScheduledEmail($subscription, $scheduled);
                }

                return ['type' => 'pix_auto', 'order' => $scheduled, 'emailed' => $sendEmail];
            }
        }

        if (! $this->productHasPix($product)) {
            return null;
        }

        try {
            $order = $this->createPendingRenewalOrder($subscription, 'pix');
            event(new OrderPending($order));

            $consumer = [
                'name' => $user->name ?? $user->email,
                'document' => FakeConsumerData::getForGateway($order->id)['document'],
                'email' => $user->email,
            ];

            $pixResult = $this->paymentService->createPixPayment($order, $product, $consumer);
            PixCheckoutDisplay::persistOnOrder($order, $pixResult);
            $order->refresh();

            event(new PixGenerated($order, [
                'qrcode' => $pixResult['qrcode'] ?? null,
                'copy_paste' => $pixResult['copy_paste'] ?? null,
                'transaction_id' => $pixResult['transaction_id'] ?? null,
            ]));

            $pix = [
                'copy_paste' => $pixResult['copy_paste'] ?? null,
                'qrcode' => $pixResult['qrcode'] ?? null,
            ];

            if ($sendEmail) {
                $this->sendPixRenewalEmail($subscription, $order, $pix);
            }

            return array_merge(['type' => 'pix', 'order' => $order, 'emailed' => $sendEmail], $pix);
        } catch (\Throwable $e) {
            Log::warning('SubscriptionAutoBillingService: falha ao gerar PIX de renovação.', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
            if (isset($order) && $order instanceof Order && $order->status === 'pending') {
                $order->delete();
            }

            return null;
        }
    }

    public function findReusablePendingRenewalOrder(Subscription $subscription): ?Order
    {
        $since = now()->subHours(self::PENDING_PIX_REUSE_HOURS);

        $order = Order::query()
            ->where('user_id', $subscription->user_id)
            ->where('product_id', $subscription->product_id)
            ->where('subscription_plan_id', $subscription->subscription_plan_id)
            ->where('is_renewal', true)
            ->where('status', 'pending')
            ->where('created_at', '>=', $since)
            ->where(function ($q) use ($subscription) {
                $q->where('metadata->auto_renewal_subscription_id', $subscription->id)
                    ->orWhereNull('metadata->auto_renewal_subscription_id');
            })
            ->orderByDesc('id')
            ->first();

        if (! $order) {
            return null;
        }

        $method = (string) data_get($order->metadata, 'checkout_payment_method', '');
        if ($method !== '' && ! in_array($method, ['pix', 'pix_auto'], true)) {
            return null;
        }

        $meta = is_array($order->metadata) ? $order->metadata : [];
        if (! PixCheckoutDisplay::hasPixPayload($meta) && $method !== 'pix_auto') {
            return null;
        }

        return $order;
    }

    /**
     * @return array{copy_paste: ?string, qrcode: ?string}|null
     */
    public function pixDataFromOrder(Order $order): ?array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $copy = $meta['pix_copy_paste'] ?? null;
        $qr = $meta['pix_qrcode'] ?? null;
        if (($copy === null || $copy === '') && ($qr === null || $qr === '')) {
            return null;
        }

        return [
            'copy_paste' => is_string($copy) ? $copy : null,
            'qrcode' => is_string($qr) ? $qr : null,
        ];
    }

    public function createPendingRenewalOrder(Subscription $subscription, string $paymentMethod): Order
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $plan = $subscription->subscriptionPlan;
        $product = $subscription->product;
        $user = $subscription->user;
        $tenantId = $subscription->tenant_id;

        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        if ($currency !== 'BRL') {
            $raw = Setting::get('currencies', null, $tenantId);
            $list = $raw
                ? (is_string($raw) ? json_decode($raw, true) : $raw)
                : config('products.currencies');
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                CheckoutCurrencyCatalog::currenciesForCheckout(is_array($list) ? $list : [])
            );
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();

        return Order::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'product_offer_id' => null,
            'subscription_plan_id' => $plan->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_renewal' => true,
            'amount' => $amount,
            'email' => $user->email,
            'cpf' => null,
            'phone' => null,
            'customer_ip' => null,
            'coupon_code' => null,
            'status' => 'pending',
            'gateway' => null,
            'gateway_id' => null,
            'metadata' => [
                'checkout_payment_method' => $paymentMethod,
                'auto_renewal_subscription_id' => $subscription->id,
                'auto_renewal' => true,
            ],
        ]);
    }

    /**
     * @param  array{copy_paste?: ?string, qrcode?: ?string}  $pix
     */
    public function sendPixRenewalEmail(Subscription $subscription, Order $order, array $pix): void
    {
        $user = $subscription->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $email = $this->lifecycle->buildReminderEmail($subscription, Carbon::today(), $pix, $order);
        try {
            $this->mailConfig->applyMailerConfigForTenant($subscription->tenant_id, [], null);
            Mail::mailer('smtp')->to($user->email)->send(
                new SubscriptionReminderMail($email['subject'], $email['body'])
            );
            $this->lifecycle->markReminderSent($subscription, Carbon::today());
        } catch (\Throwable $e) {
            Log::warning('SubscriptionAutoBillingService: falha ao enviar e-mail PIX de renovação.', [
                'subscription_id' => $subscription->id,
                'order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function sendPixAutoScheduledEmail(Subscription $subscription, Order $order): void
    {
        $user = $subscription->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $productName = e($subscription->product?->name ?? 'produto');
        $greeting = $user->name ? ', '.e($user->name) : '';
        $renewalUrl = url('/renovar/'.$subscription->renewal_token);
        $subject = 'Renovação agendada: '.$subscription->product?->name;
        $body = '<p>Olá'.$greeting.'!</p>';
        $body .= '<p>O débito <strong>PIX automático</strong> da sua assinatura de <strong>'.$productName.'</strong> foi agendado (pedido #'.e((string) $order->id).').</p>';
        $body .= '<p>Você receberá a confirmação quando o pagamento for processado.</p>';
        $body .= '<p>Se precisar, acesse: <a href="'.e($renewalUrl).'">'.e($renewalUrl).'</a></p>';

        try {
            $this->mailConfig->applyMailerConfigForTenant($subscription->tenant_id, [], null);
            Mail::mailer('smtp')->to($user->email)->send(new SubscriptionReminderMail($subject, $body));
            $this->lifecycle->markReminderSent($subscription, Carbon::today());
        } catch (\Throwable $e) {
            Log::warning('SubscriptionAutoBillingService: falha e-mail pix_auto.', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function shouldEmailPix(Subscription $subscription): bool
    {
        return $this->lifecycle->shouldSendReminderToday($subscription, Carbon::today());
    }

    private function productHasPix($product): bool
    {
        $pg = is_array($product->checkout_config['payment_gateways'] ?? null)
            ? $product->checkout_config['payment_gateways']
            : [];
        $slug = isset($pg['pix']) ? trim((string) $pg['pix']) : '';

        return $slug !== '';
    }

    private function trySchedulePixAuto(Subscription $subscription): ?Order
    {
        if ($this->isCajuPayManagedSubscription($subscription)) {
            return null;
        }

        $tenantId = $subscription->tenant_id;
        $user = $subscription->user;
        $idRec = $subscription->gateway_subscription_id;
        if (! $idRec || ! $user) {
            return null;
        }

        $credential = GatewayCredential::forTenant($tenantId)
            ->where('gateway_slug', 'efi')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return null;
        }

        $credentials = $credential->getDecryptedCredentials();
        if (empty($credentials['certificate_path'])) {
            return null;
        }

        try {
            $order = $this->createPendingRenewalOrder($subscription, 'pix_auto');
            event(new OrderPending($order));

            $plan = $subscription->subscriptionPlan;
            $amount = (float) $order->amount;
            $dataDeVencimento = $order->period_end
                ? Carbon::parse($order->period_end)->format('Y-m-d')
                : now()->addMonth()->format('Y-m-d');
            $base = 'pixautorenov'.$order->id;
            $txid = $base.\Illuminate\Support\Str::random(max(26 - strlen($base), 10));
            $txid = substr($txid, 0, 35);
            $devedor = [
                'name' => $user->name ?? $user->email,
                'email' => $user->email,
            ];
            $service = new EfiPixRecorrenteService($credentials);
            $service->createCobrancaRecorrente(
                $idRec,
                $amount,
                $dataDeVencimento,
                $txid,
                $devedor,
                'Renovação assinatura #'.$subscription->id
            );
            $order->update([
                'gateway' => 'efi',
                'gateway_id' => $txid,
            ]);

            return $order->fresh();
        } catch (\Throwable $e) {
            Log::warning('SubscriptionAutoBillingService: falha pix_auto renovação.', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
            if (isset($order) && $order instanceof Order && $order->status === 'pending') {
                $order->delete();
            }

            return null;
        }
    }

    private function isCajuPayManagedSubscription(Subscription $subscription): bool
    {
        try {
            return app(CajuPaySubscriptionService::class)->isCajuPayManaged($subscription);
        } catch (\Throwable) {
            return false;
        }
    }
}
