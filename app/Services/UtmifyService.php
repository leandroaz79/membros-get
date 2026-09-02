<?php

namespace App\Services;

use App\Models\ApiCheckoutSession;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\OrderReportingAmounts;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UtmifyService
{
    private const ENDPOINT = 'https://api.utmify.com.br/api-credentials/orders';

    /**
     * Build payload(s) and send order line(s) to UTMfy API (1 POST per product / order bump).
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @param  array<int, string>|null  $onlyProductIds  When set, restricts which lines to send (see buildPayloads).
     */
    public function sendOrder(
        Order $order,
        string $utmifyStatus,
        string $apiKey,
        array $options = [],
        ?array $onlyProductIds = null
    ): void {
        $payloads = $this->buildPayloads($order, $utmifyStatus, $options, $onlyProductIds);
        if ($payloads === []) {
            return;
        }

        $errors = [];
        foreach ($payloads as $body) {
            try {
                $this->post($apiKey, $body);
            } catch (\Throwable $e) {
                $errors[] = [
                    'orderId' => $body['orderId'] ?? null,
                    'message' => $e->getMessage(),
                ];
                Log::warning('UtmifyService line post failed', [
                    'getfy_order_id' => $order->id,
                    'utmify_order_id' => $body['orderId'] ?? null,
                    'status' => $utmifyStatus,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException(
                'UTMfy API error on '.count($errors).'/'.count($payloads).' payload(s): '
                .json_encode($errors, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Single payload (first line). Prefer {@see buildPayloads} for multi-item orders.
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @param  array<int, string>|null  $onlyProductIds
     * @return array<string, mixed>
     */
    public function buildPayload(
        Order $order,
        string $utmifyStatus,
        array $options = [],
        ?array $onlyProductIds = null
    ): array {
        $payloads = $this->buildPayloads($order, $utmifyStatus, $options, $onlyProductIds);
        if ($payloads !== []) {
            return $payloads[0];
        }

        // Filtro por produto sem linhas correspondentes: não inventar payload com o total do funil.
        if ($onlyProductIds !== null) {
            return [];
        }

        return $this->emptyFallbackPayload($order, $utmifyStatus, $options);
    }

    /**
     * One UTMfy order body per order line (main + each bump), each with its own commission.
     *
     * Product filter semantics (produtos atribuídos na integração):
     * - null / vazio → todas as linhas
     * - produto da linha está no filtro → envia a linha
     * - produto principal do pedido (checkout) está no filtro → envia TODAS as linhas
     *   (order bumps do mesmo checkout entram mesmo sem o produto do bump estar marcado)
     *
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @param  array<int, string>|null  $onlyProductIds
     * @return array<int, array<string, mixed>>
     */
    public function buildPayloads(
        Order $order,
        string $utmifyStatus,
        array $options = [],
        ?array $onlyProductIds = null
    ): array {
        $order->loadMissing(['user', 'product', 'orderItems.product', 'orderItems.productOffer', 'orderItems.subscriptionPlan']);

        $session = CheckoutSession::where('order_id', $order->id)->orderByDesc('id')->first();
        $apiSession = null;
        if (! $session) {
            if ($order->api_checkout_session_id !== null) {
                $apiSession = ApiCheckoutSession::find($order->api_checkout_session_id);
            }
            if (! $apiSession) {
                $apiSession = ApiCheckoutSession::where('order_id', $order->id)->first();
            }
        }

        $baseOrderId = $this->baseUtmifyOrderId($order);
        $paymentMethod = $this->mapPaymentMethod($order);
        $createdAt = $order->created_at->utc()->format('Y-m-d H:i:s');
        $approvedDate = $options['approved_at'] ?? ($utmifyStatus === 'paid' ? $order->updated_at->utc()->format('Y-m-d H:i:s') : null);
        $refundedAt = $options['refunded_at'] ?? null;

        $apiCustomer = $apiSession?->customer;
        $apiCustomer = is_array($apiCustomer) ? $apiCustomer : [];

        $customerName = $session?->name
            ?? (string) ($apiCustomer['name'] ?? '')
            ?? $order->user?->name
            ?? '';
        $customer = [
            'name' => $customerName,
            'email' => $order->email ?? '',
            'phone' => $order->phone ?? '',
            'document' => $order->cpf ?? '',
            'country' => 'BR',
            'ip' => $order->customer_ip ?? '',
        ];

        $apiMeta = $apiSession?->metadata;
        $apiMeta = is_array($apiMeta) ? $apiMeta : [];
        $trackingParameters = $this->buildMergedTrackingParameters($session, $apiMeta, $order);

        $filterIds = null;
        if ($onlyProductIds !== null) {
            $filterIds = array_values(array_unique(array_filter(
                array_map(static fn ($id) => (string) $id, $onlyProductIds),
                static fn (string $id) => $id !== ''
            )));
            if ($filterIds === []) {
                $filterIds = null;
            }
        }

        $checkoutMainLinked = $filterIds !== null
            && in_array((string) ($order->product_id ?? ''), $filterIds, true);

        $allItems = $order->orderItems;
        if ($allItems->isEmpty()) {
            $totalCentsBrl = OrderReportingAmounts::totalCentsBrl($order);
            $mainProduct = $order->product;
            $productId = (string) ($mainProduct?->id ?? $order->product_id ?? '');
            if ($filterIds !== null && ! $checkoutMainLinked && ($productId === '' || ! in_array($productId, $filterIds, true))) {
                return [];
            }

            $body = $this->makeBody(
                orderId: $baseOrderId,
                paymentMethod: $paymentMethod,
                utmifyStatus: $utmifyStatus,
                createdAt: $createdAt,
                approvedDate: $approvedDate,
                refundedAt: $refundedAt,
                customer: $customer,
                products: [[
                    'id' => $productId !== '' ? $productId : (string) ($order->product_id ?? '0'),
                    'name' => $mainProduct?->name ?? 'Produto',
                    'planId' => null,
                    'planName' => null,
                    'quantity' => 1,
                    'priceInCents' => $totalCentsBrl,
                ]],
                trackingParameters: $trackingParameters,
                commissionCents: $totalCentsBrl,
                isTest: ! empty($options['is_test'])
            );

            return [$body];
        }

        // Rateio sempre sobre o pedido completo; o filtro só decide quais linhas enviar.
        $lineAmounts = $allItems->map(fn (OrderItem $item) => (float) $item->amount)->values()->all();
        $lineCents = OrderReportingAmounts::allocateLineCentsBrl($order, $lineAmounts);

        $payloads = [];
        foreach ($allItems->values() as $index => $item) {
            /** @var OrderItem $item */
            $productId = (string) ($item->product_id ?? '');
            if ($filterIds !== null
                && ! $checkoutMainLinked
                && ! in_array($productId, $filterIds, true)) {
                continue;
            }

            $product = $item->product;
            $planId = $item->product_offer_id ?? $item->subscription_plan_id;
            $planName = null;
            if ($item->productOffer) {
                $planName = $item->productOffer->name;
            } elseif ($item->subscriptionPlan) {
                $planName = $item->subscriptionPlan->name;
            }

            $cents = (int) ($lineCents[$index] ?? 0);
            // Evita rejeição da API quando commission / preço ficam zerados.
            if ($cents <= 0) {
                $cents = max(1, OrderReportingAmounts::lineCentsBrl($order, (float) $item->amount));
            }

            $utmifyOrderId = $this->utmifyOrderIdForLine($baseOrderId, $item);

            $payloads[] = $this->makeBody(
                orderId: $utmifyOrderId,
                paymentMethod: $paymentMethod,
                utmifyStatus: $utmifyStatus,
                createdAt: $createdAt,
                approvedDate: $approvedDate,
                refundedAt: $refundedAt,
                customer: $customer,
                products: [[
                    'id' => (string) ($product?->id ?? $item->product_id ?? $item->id),
                    'name' => $product?->name ?? 'Produto',
                    'planId' => $planId ? (string) $planId : null,
                    'planName' => $planName,
                    'quantity' => 1,
                    'priceInCents' => $cents,
                ]],
                trackingParameters: $trackingParameters,
                commissionCents: $cents,
                isTest: ! empty($options['is_test'])
            );
        }

        return $payloads;
    }

    /**
     * POST to UTMfy API. Throws on failure.
     */
    public function post(string $apiKey, array $body): \Illuminate\Http\Client\Response
    {
        $response = Http::timeout(15)
            ->withHeaders(['x-api-token' => $apiKey])
            ->post(self::ENDPOINT, $body);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'UTMfy API error: '.$response->status().' '.$response->body()
            );
        }

        return $response;
    }

    /**
     * orderId estável do pedido principal na UTMfy.
     */
    public function baseUtmifyOrderId(Order $order): string
    {
        $gatewayId = trim((string) ($order->gateway_id ?? ''));

        return $gatewayId !== '' ? $gatewayId : (string) $order->id;
    }

    /**
     * orderId por linha: principal usa o id base; bumps usam sufixo estável.
     */
    public function utmifyOrderIdForLine(string $baseOrderId, OrderItem $item): string
    {
        $isMainLine = (int) ($item->position ?? 0) === 0;
        if ($isMainLine) {
            return $baseOrderId;
        }

        $suffix = $item->id ?: ('p'.$item->position);

        return $baseOrderId.'-ob-'.$suffix;
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<int, array<string, mixed>>  $products
     * @param  array<string, string|null>  $trackingParameters
     * @return array<string, mixed>
     */
    private function makeBody(
        string $orderId,
        string $paymentMethod,
        string $utmifyStatus,
        string $createdAt,
        ?string $approvedDate,
        ?string $refundedAt,
        array $customer,
        array $products,
        array $trackingParameters,
        int $commissionCents,
        bool $isTest
    ): array {
        $body = [
            'orderId' => $orderId,
            'platform' => 'Primicia',
            'paymentMethod' => $paymentMethod,
            'status' => $utmifyStatus,
            'createdAt' => $createdAt,
            'approvedDate' => $approvedDate,
            'refundedAt' => $refundedAt,
            'customer' => $customer,
            'products' => $products,
            'trackingParameters' => $trackingParameters,
            'commission' => [
                'totalPriceInCents' => $commissionCents,
                'gatewayFeeInCents' => 0,
                'userCommissionInCents' => $commissionCents,
            ],
        ];

        if ($isTest) {
            $body['isTest'] = true;
        }

        return $body;
    }

    /**
     * @param  array{approved_at?: string|null, refunded_at?: string|null, is_test?: bool}  $options
     * @return array<string, mixed>
     */
    private function emptyFallbackPayload(Order $order, string $utmifyStatus, array $options): array
    {
        $totalCentsBrl = OrderReportingAmounts::totalCentsBrl($order);
        $createdAt = $order->created_at?->utc()->format('Y-m-d H:i:s') ?? now()->utc()->format('Y-m-d H:i:s');

        return $this->makeBody(
            orderId: $this->baseUtmifyOrderId($order),
            paymentMethod: $this->mapPaymentMethod($order),
            utmifyStatus: $utmifyStatus,
            createdAt: $createdAt,
            approvedDate: $options['approved_at'] ?? ($utmifyStatus === 'paid' ? ($order->updated_at?->utc()->format('Y-m-d H:i:s')) : null),
            refundedAt: $options['refunded_at'] ?? null,
            customer: [
                'name' => $order->user?->name ?? '',
                'email' => $order->email ?? '',
                'phone' => $order->phone ?? '',
                'document' => $order->cpf ?? '',
                'country' => 'BR',
                'ip' => $order->customer_ip ?? '',
            ],
            products: [[
                'id' => (string) ($order->product_id ?? '0'),
                'name' => 'Produto',
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => $totalCentsBrl,
            ]],
            trackingParameters: $this->buildMergedTrackingParameters(null, [], $order),
            commissionCents: $totalCentsBrl,
            isTest: ! empty($options['is_test'])
        );
    }

    /**
     * Mescla parâmetros de tráfego: camadas posteriores sobrescrevem as anteriores (metadata do pedido ganha).
     *
     * @param  array<string, mixed>  $apiMeta
     * @return array<string, string|null>
     */
    private function buildMergedTrackingParameters(?CheckoutSession $session, array $apiMeta, Order $order): array
    {
        $keys = [
            'src', 'sck',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'fbclid', 'gclid', 'msclkid', 'fbp', 'fbc',
        ];

        $sessionUtm = [
            'utm_source' => $session?->utm_source,
            'utm_medium' => $session?->utm_medium,
            'utm_campaign' => $session?->utm_campaign,
        ];
        $sessionTracking = is_array($session?->tracking_metadata) ? $session->tracking_metadata : [];
        $orderMeta = is_array($order->metadata) ? $order->metadata : [];

        $layers = [$apiMeta, $sessionUtm, $sessionTracking, $orderMeta];

        $out = array_fill_keys($keys, null);
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }
            foreach ($keys as $k) {
                if (! array_key_exists($k, $layer)) {
                    continue;
                }
                $v = $layer[$k];
                if (! is_string($v) && ! is_numeric($v)) {
                    continue;
                }
                $s = trim((string) $v);
                if ($s === '') {
                    continue;
                }
                $out[$k] = mb_substr($s, 0, 512);
            }
        }

        return $out;
    }

    private function mapPaymentMethod(Order $order): string
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $checkoutMethod = strtolower((string) ($meta['checkout_payment_method'] ?? ''));

        return match ($checkoutMethod) {
            'pix', 'pix_auto' => 'pix',
            'boleto' => 'boleto',
            'card', 'apple_pay', 'google_pay', 'paypal' => 'credit_card',
            default => $this->mapPaymentMethodFromGatewaySlug($order->gateway),
        };
    }

    private function mapPaymentMethodFromGatewaySlug(?string $gateway): string
    {
        if (! $gateway) {
            return 'pix';
        }
        $g = strtolower($gateway);
        if (str_contains($g, 'pix')) {
            return 'pix';
        }
        if (str_contains($g, 'boleto') || str_contains($g, 'ticket')) {
            return 'boleto';
        }
        if (str_contains($g, 'card') || str_contains($g, 'credit') || str_contains($g, 'cartao') || str_contains($g, 'cajupay') || str_contains($g, 'paypal')) {
            return 'credit_card';
        }

        return 'pix';
    }
}
