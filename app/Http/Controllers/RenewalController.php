<?php

namespace App\Http\Controllers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\SubscriptionRenewed;
use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\EfiPixRecorrenteService;
use App\Services\GeoIp;
use App\Services\SubscriptionAutoBillingService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CheckoutCurrencyCatalog;
use App\Support\CheckoutPaymentMethodOrder;
use App\Support\PixCheckoutDisplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RenewalController extends Controller
{
    public function show(Request $request, string $token, SubscriptionLifecycleService $lifecycle): Response|RedirectResponse
    {
        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $token)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        if (! $lifecycle->canRenew($subscription)) {
            return redirect()->route('login')->with(
                'error',
                'O prazo para renovação desta assinatura expirou. Entre em contato com o suporte.'
            );
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        $tenantId = $subscription->tenant_id;
        if ($currency !== 'BRL') {
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                $this->tenantCurrencies($tenantId)
            );
        }

        $suggestions = (new GeoIp)->getSuggestionsForRequest($request);
        $availablePaymentMethods = CheckoutPaymentMethodOrder::applyForCountry(
            $this->buildAvailablePaymentMethods($product, $subscription),
            $suggestions['country_code'] ?? null
        );
        $savedPaymentMethods = $subscription->user->savedPaymentMethods()->forTenant($tenantId)->get()->map(fn ($m) => [
            'id' => $m->id,
            'type' => $m->type,
            'last_four' => $m->last_four,
            'brand' => $m->brand,
        ])->all();

        $productArray = [
            'id' => $product->id,
            'name' => $product->name,
            'checkout_slug' => $plan->checkout_slug,
        ];
        $planArray = [
            'id' => $plan->id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $currency,
            'interval' => $plan->interval,
        ];

        return Inertia::render('Renewal/Show', [
            'token' => $token,
            'subscription' => [
                'id' => $subscription->id,
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'renewable_until' => $lifecycle->renewableUntil($subscription)?->toDateString(),
                'status' => $subscription->status,
                'effective_status' => $lifecycle->effectiveStatus($subscription),
            ],
            'product' => $productArray,
            'plan' => $planArray,
            'amount' => round($amount, 2),
            'amount_brl' => round($amount, 2),
            'available_payment_methods' => $availablePaymentMethods,
            'saved_payment_methods' => $savedPaymentMethods,
            'auto_pix_url' => url('/renovar/'.$token.'/pix'),
            'card_offsession_available' => false,
        ]);
    }

    /**
     * Gera (ou reutiliza) PIX de renovação e redireciona para a tela do QR — sem formulário resumido.
     */
    public function payPix(Request $request, string $token, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $token)
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        if (! $lifecycle->canRenew($subscription)) {
            return redirect()->route('login')->with(
                'error',
                'O prazo para renovação desta assinatura expirou. Entre em contato com o suporte.'
            );
        }

        try {
            $billing = app(SubscriptionAutoBillingService::class);
            $result = $billing->ensureRenewalPayment($subscription, sendEmail: false);

            if (! is_array($result) || empty($result['order'])) {
                return redirect()->route('renewal.show', $token)
                    ->with('error', 'Não foi possível gerar o PIX. Escolha outra forma de pagamento.');
            }

            /** @var Order $order */
            $order = $result['order'];

            if (($result['type'] ?? '') === 'pix_auto') {
                return redirect()->route('renewal.show', $token)
                    ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
            }

            $pix = [
                'qrcode' => $result['qrcode'] ?? null,
                'copy_paste' => $result['copy_paste'] ?? null,
            ];
            if (! $pix['copy_paste'] && ! $pix['qrcode']) {
                $fromOrder = $billing->pixDataFromOrder($order);
                $pix = $fromOrder ?? $pix;
            }

            $displayToken = PixCheckoutDisplay::storeSession($order, $pix, [
                'amount' => (float) $order->amount,
                'product_name' => $subscription->product?->name,
                'checkout_slug' => $subscription->subscriptionPlan?->checkout_slug
                    ?: $subscription->product?->checkout_slug,
                'customer_name' => $subscription->user?->name,
                'customer_email' => $subscription->user?->email,
            ]);

            return redirect()->route('checkout.pix', ['token' => $displayToken]);
        } catch (\Throwable $e) {
            return redirect()->route('renewal.show', $token)
                ->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX. Tente novamente.');
        }
    }

    public function process(Request $request, SubscriptionLifecycleService $lifecycle): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:64'],
            'payment_method' => ['nullable', 'string', 'in:pix,card,boleto,pix_auto,manual'],
        ]);

        $subscription = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->where('renewal_token', $request->input('token'))
            ->whereIn('status', [Subscription::STATUS_ACTIVE, Subscription::STATUS_PAST_DUE])
            ->first();

        if (! $subscription || $subscription->subscriptionPlan->isLifetime()) {
            return redirect()->route('login')->with('error', 'Link de renovação inválido ou expirado.');
        }

        if (! $lifecycle->canRenew($subscription)) {
            return redirect()->route('login')->with(
                'error',
                'O prazo para renovação desta assinatura expirou. Entre em contato com o suporte.'
            );
        }

        $product = $subscription->product;
        $plan = $subscription->subscriptionPlan;
        $user = $subscription->user;
        $tenantId = $subscription->tenant_id;
        $amount = (float) $plan->price;
        $currency = $plan->getCurrencyOrDefault();
        if ($currency !== 'BRL') {
            $amount = CheckoutCurrencyCatalog::brlFromForeignAmount(
                $amount,
                $currency,
                $this->tenantCurrencies($tenantId)
            );
        }

        [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        $paymentMethod = $request->input('payment_method', 'manual');

        $orderPayload = [
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
            'customer_ip' => $request->ip(),
            'coupon_code' => null,
        ];

        if ($paymentMethod === 'pix_auto' && $subscription->gateway_subscription_id) {
            $credential = GatewayCredential::forTenant($tenantId)
                ->where('gateway_slug', 'efi')
                ->where('is_connected', true)
                ->first();
            if ($credential) {
                $credentials = $credential->getDecryptedCredentials();
                if (! empty($credentials['certificate_path'])) {
                    $order = Order::create(array_merge($orderPayload, [
                        'status' => 'pending',
                        'gateway' => 'efi',
                        'gateway_id' => null,
                        'metadata' => ['checkout_payment_method' => 'pix_auto'],
                    ]));
                    event(new OrderPending($order));
                    try {
                        $idRec = $subscription->gateway_subscription_id;
                        $dataDeVencimento = $periodEnd ? $periodEnd->format('Y-m-d') : now()->addMonth()->format('Y-m-d');
                        $base = 'pixautorenov' . $order->id;
                        $txid = $base . \Illuminate\Support\Str::random(max(26 - strlen($base), 10));
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
                            'Renovação assinatura #' . $subscription->id
                        );
                        $order->update(['gateway_id' => $txid]);
                        return redirect()->route('renewal.show', $request->input('token'))
                            ->with('info', 'O débito PIX automático foi agendado. Você receberá a confirmação quando o pagamento for processado.');
                    } catch (\Throwable $e) {
                        $order->delete();
                        return back()->with('error', $e->getMessage() ?: 'Não foi possível agendar o PIX automático. Tente outro método.');
                    }
                }
            }
        }

        if ($paymentMethod === 'pix') {
            return redirect()->route('renewal.pix', $request->input('token'));
        }

        if ($paymentMethod === 'card' || $paymentMethod === 'boleto') {
            // Cartão off-session não está disponível (token não é salvo no checkout).
            // Direciona o cliente ao checkout do plano/produto para cobranca atenciosa com 3DS/tokenização.
            if ($paymentMethod === 'card') {
                $slug = $plan->checkout_slug ?: $product->checkout_slug;
                if ($slug) {
                    return redirect()->to(url('/c/'.$slug).'?renewal_token='.urlencode((string) $request->input('token')))
                        ->with('info', 'Informe os dados do cartão para renovar a assinatura.');
                }

                return back()->with(
                    'error',
                    'Renovação automática com cartão exige cartão salvo no gateway (ainda não disponível). Use PIX ou o checkout do produto.'
                );
            }

            $order = Order::create(array_merge($orderPayload, [
                'status' => 'pending',
                'gateway' => $paymentMethod,
                'gateway_id' => null,
                'metadata' => ['checkout_payment_method' => 'boleto'],
            ]));
            event(new OrderPending($order));
            event(new BoletoGenerated($order));

            return redirect()->route('renewal.show', $request->input('token'))
                ->with('info', 'Pedido de boleto criado. Você receberá as instruções por e-mail em breve.');
        }

        $order = Order::create(array_merge($orderPayload, [
            'status' => 'completed',
            'gateway' => 'manual',
        ]));
        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'past_due_at' => null,
        ]);
        event(new SubscriptionRenewed($subscription->fresh()));
        event(new OrderCompleted($order));

        return redirect()->route('renewal.show', $request->input('token'))
            ->with('success', 'Renovação concluída. Seu acesso foi estendido.');
    }

    /**
     * @param  Subscription|null  $subscription  Quando presente e com gateway_subscription_id (idRec), inclui pix_auto se Efí estiver conectada.
     */
    private function buildAvailablePaymentMethods($product, ?Subscription $subscription = null): array
    {
        $tenantId = $product->tenant_id;
        $config = $product->checkout_config ?? [];
        $pg = $config['payment_gateways'] ?? [];
        $orderRaw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($orderRaw)) {
            $orderRaw = json_decode($orderRaw, true);
        }
        $defaultOrder = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        $order = is_array($orderRaw) ? $orderRaw : $defaultOrder;
        $order = [
            'pix' => $order['pix'] ?? $defaultOrder['pix'] ?? [],
            'card' => $order['card'] ?? $defaultOrder['card'] ?? [],
            'boleto' => $order['boleto'] ?? $defaultOrder['boleto'] ?? [],
            'pix_auto' => $order['pix_auto'] ?? $defaultOrder['pix_auto'] ?? [],
        ];

        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->where('is_connected', true)
            ->get()
            ->keyBy('gateway_slug');

        $methods = [];
        $methodConfig = [
            'pix' => ['id' => 'pix', 'label' => 'PIX'],
            'card' => ['id' => 'card', 'label' => 'Cartão'],
            'boleto' => ['id' => 'boleto', 'label' => 'Boleto'],
            'pix_auto' => ['id' => 'pix_auto', 'label' => 'PIX automático'],
        ];

        foreach ($methodConfig as $methodKey => $meta) {
            if ($methodKey === 'pix_auto') {
                if ($subscription === null || $subscription->gateway_subscription_id === null || $subscription->gateway_subscription_id === '') {
                    continue;
                }
            }
            $productSlug = isset($pg[$methodKey]) ? trim((string) $pg[$methodKey]) : null;
            if ($productSlug === null || $productSlug === '') {
                continue;
            }
            if ($productSlug === '__default__') {
                $slugsToCheck = is_array($order[$methodKey] ?? null) ? $order[$methodKey] : [];
            } else {
                $redundancy = $pg[$methodKey . '_redundancy'] ?? [];
                $redundancy = is_array($redundancy) ? $redundancy : [];
                $slugsToCheck = array_merge([$productSlug], $redundancy);
            }

            foreach ($slugsToCheck as $slug) {
                $cred = $credentialBySlug->get($slug);
                if (! $cred) {
                    continue;
                }
                $gateway = GatewayRegistry::get($slug);
                if (! $gateway || ! in_array($methodKey, $gateway['methods'] ?? [], true)) {
                    continue;
                }
                $methods[] = [
                    'id' => $meta['id'],
                    'label' => $meta['label'],
                    'gateway_slug' => $slug,
                    'gateway_name' => $gateway['name'] ?? $slug,
                ];
                break;
            }
        }

        return $methods;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tenantCurrencies(?int $tenantId): array
    {
        $raw = Setting::get('currencies', null, $tenantId);
        $list = $raw
            ? (is_string($raw) ? json_decode($raw, true) : $raw)
            : config('products.currencies');

        return CheckoutCurrencyCatalog::currenciesForCheckout(is_array($list) ? $list : []);
    }
}
