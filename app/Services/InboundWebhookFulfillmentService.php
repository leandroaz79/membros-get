<?php

namespace App\Services;

use App\Events\OrderCompleted;
use App\Events\SubscriptionCreated;
use App\Models\InboundWebhookEndpoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InboundWebhookFulfillmentService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok:bool,status:int,json:array,messages?:string}
     */
    public function fulfill(InboundWebhookEndpoint $endpoint, array $payload): array
    {
        $map = $endpoint->normalizedFieldMap();
        $email = InboundWebhookEndpoint::resolvePayloadValue($payload, $map['email'] ?? 'email');
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'status' => 422, 'json' => ['success' => false, 'message' => 'E-mail inválido ou ausente no payload.'], 'messages' => 'invalid email'];
        }

        $name = InboundWebhookEndpoint::resolvePayloadValue($payload, $map['name'] ?? 'name');
        $cpf = InboundWebhookEndpoint::resolvePayloadValue($payload, $map['cpf'] ?? 'cpf');
        $phone = InboundWebhookEndpoint::resolvePayloadValue($payload, $map['phone'] ?? 'phone');
        $externalId = InboundWebhookEndpoint::resolvePayloadValue($payload, $map['external_id'] ?? 'external_id');

        $product = Product::query()
            ->where('id', $endpoint->product_id)
            ->where('tenant_id', $endpoint->tenant_id)
            ->first();

        if (! $product) {
            return ['ok' => false, 'status' => 404, 'json' => ['success' => false, 'message' => 'Produto não encontrado para este endpoint.']];
        }

        if ($product->type !== Product::TYPE_AREA_MEMBROS) {
            return ['ok' => false, 'status' => 422, 'json' => ['success' => false, 'message' => 'Este webhook suporta apenas produtos do tipo Área de membros.']];
        }

        $productOfferId = $endpoint->product_offer_id ? (int) $endpoint->product_offer_id : null;
        $subscriptionPlanId = $endpoint->subscription_plan_id ? (int) $endpoint->subscription_plan_id : null;

        if ($productOfferId && $subscriptionPlanId) {
            return ['ok' => false, 'status' => 422, 'json' => ['success' => false, 'message' => 'Configure apenas oferta OU plano de assinatura, não ambos.']];
        }

        $offer = null;
        $plan = null;
        if ($productOfferId !== null && $productOfferId > 0) {
            $offer = ProductOffer::query()->where('id', $productOfferId)->where('product_id', $product->id)->first();
            if (! $offer) {
                return ['ok' => false, 'status' => 422, 'json' => ['success' => false, 'message' => 'Oferta não pertence ao produto.']];
            }
        }
        if ($subscriptionPlanId !== null && $subscriptionPlanId > 0) {
            $plan = SubscriptionPlan::query()->where('id', $subscriptionPlanId)->where('product_id', $product->id)->first();
            if (! $plan) {
                return ['ok' => false, 'status' => 422, 'json' => ['success' => false, 'message' => 'Plano não pertence ao produto.']];
            }
        }

        $amount = self::effectiveAmountBrl($product, $offer, $plan);
        $periodStart = null;
        $periodEnd = null;
        if ($plan) {
            [$periodStart, $periodEnd] = $plan->getCurrentPeriod();
        }

        try {
            $tenantId = (int) $endpoint->tenant_id;
            [$orderId, $duplicate] = DB::transaction(function () use (
                $endpoint,
                $product,
                $plan,
                $email,
                $name,
                $cpf,
                $phone,
                $externalId,
                $tenantId,
                $productOfferId,
                $subscriptionPlanId,
                $amount,
                $periodStart,
                $periodEnd
            ): array {
                if ($externalId !== '') {
                    $dup = Order::query()
                        ->where('tenant_id', $tenantId)
                        ->where('product_id', $product->id)
                        ->where('metadata->inbound_external_id', $externalId)
                        ->exists();

                    if (! $dup) {
                        $dup = Order::query()
                            ->where('tenant_id', $tenantId)
                            ->where('product_id', $product->id)
                            ->orderByDesc('id')
                            ->limit(400)
                            ->get()
                            ->contains(function (Order $o) use ($externalId): bool {
                                $v = strtolower(trim((string) (($o->metadata ?? [])['inbound_external_id'] ?? '')));

                                return $v !== '' && $v === strtolower($externalId);
                            });
                    }

                    if ($dup) {
                        return [null, true];
                    }
                }

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $name !== '' ? $name : $email,
                        'password' => bcrypt(Str::random(32)),
                        'role' => User::ROLE_ALUNO,
                        'tenant_id' => $tenantId,
                    ]
                );
                $wasNew = $user->wasRecentlyCreated;
                $plainPassword = null;
                $encryptedMeta = null;

                if ($product->type === Product::TYPE_AREA_MEMBROS) {
                    if ($wasNew) {
                        $loginConfig = $product->member_area_config['login'] ?? [];
                        $passwordMode = $loginConfig['password_mode'] ?? 'auto';
                        $defaultPassword = trim((string) ($loginConfig['default_password'] ?? ''));
                        if ($passwordMode === 'default' && $defaultPassword !== '') {
                            $plainPassword = $defaultPassword;
                        } else {
                            $plainPassword = Str::random(12);
                        }
                        $passwordHash = bcrypt((string) $plainPassword);
                        $user->update(['password' => $passwordHash, 'role' => User::ROLE_ALUNO]);
                        Cache::put('access_password.'.$user->id.'.'.$product->id, $plainPassword, now()->addHours(2));
                        $encryptedMeta = encrypt((string) $plainPassword);
                    }
                }

                $orderMetadata = array_filter([
                    'checkout_payment_method' => 'external',
                    'inbound_external_id' => $externalId !== '' ? $externalId : null,
                    'inbound_endpoint_id' => $endpoint->id,
                    'access_password_temp' => $encryptedMeta,
                ], fn ($v) => $v !== null && $v !== '');

                $gatewayId = $externalId !== '' ? Str::limit($externalId, 250, '') : null;

                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'product_offer_id' => $productOfferId,
                    'subscription_plan_id' => $subscriptionPlanId,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'is_renewal' => false,
                    'status' => 'completed',
                    'amount' => $amount,
                    'email' => $email,
                    'cpf' => $cpf !== '' ? $cpf : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'customer_ip' => null,
                    'coupon_code' => null,
                    'gateway' => 'inbound_webhook',
                    'gateway_id' => $gatewayId,
                    'approved_manually' => false,
                    'metadata' => $orderMetadata,
                ]);

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_offer_id' => $productOfferId,
                    'subscription_plan_id' => $subscriptionPlanId,
                    'amount' => $amount,
                    'position' => 0,
                ]);

                $order->loadMissing('orderItems');
                $order->grantPurchasedProductAccessToBuyer();

                if ($subscriptionPlanId && $plan) {
                    $already = Subscription::query()
                        ->where('user_id', $order->user_id)
                        ->where('product_id', $product->id)
                        ->where('subscription_plan_id', $plan->id)
                        ->where('status', Subscription::STATUS_ACTIVE)
                        ->exists();
                    if (! $order->is_renewal && ! $already) {
                        $subscription = Subscription::create([
                            'tenant_id' => $tenantId,
                            'user_id' => $order->user_id,
                            'product_id' => $product->id,
                            'subscription_plan_id' => $plan->id,
                            'status' => Subscription::STATUS_ACTIVE,
                            'current_period_start' => $periodStart,
                            'current_period_end' => $periodEnd,
                        ]);
                        event(new SubscriptionCreated($subscription));
                    }
                }

                event(new OrderCompleted($order));

                return [$order->id, false];
            });

            if ($duplicate) {
                return ['ok' => true, 'status' => 200, 'json' => ['success' => true, 'duplicate' => true, 'message' => 'Já processado (idempotência).']];
            }

            Log::info('CheckoutExterno: pedido criado.', ['order_id' => $orderId, 'endpoint_id' => $endpoint->id]);

            return ['ok' => true, 'status' => 200, 'json' => ['success' => true, 'order_id' => $orderId]];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'status' => 500, 'json' => ['success' => false, 'message' => 'Erro ao processar webhook.'], 'messages' => $e->getMessage()];
        }
    }

    public static function effectiveAmountBrl(Product $product, ?ProductOffer $offer, ?SubscriptionPlan $plan): float
    {
        $rates = config('products.rates', []);
        $price = (float) $product->price;
        if ($offer) {
            $price = (float) $offer->price;
        } elseif ($plan) {
            $price = (float) $plan->price;
        }
        $currency = $product->currency ?? 'BRL';
        if ($offer) {
            $currency = $offer->getCurrencyOrDefault();
        } elseif ($plan) {
            $currency = $plan->getCurrencyOrDefault();
        }
        if ($currency !== 'BRL') {
            $price = $currency === 'EUR'
                ? $price / (float) ($rates['brl_eur'] ?? 0.16)
                : $price / (float) ($rates['brl_usd'] ?? 0.18);
        }

        return round($price, 2);
    }
}
