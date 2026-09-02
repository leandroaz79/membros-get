<?php

namespace App\Services\Commerce;

use App\Events\BoletoGenerated;
use App\Events\CheckoutBeforeProcess;
use App\Events\OrderCompleted;
use App\Events\OrderPending;
use App\Events\PixGenerated;
use App\Models\CommerceCheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Plugins\Commerce\CommerceCheckoutContextRegistry;
use App\Plugins\PluginCheckoutExtensionRegistry;
use App\Plugins\PluginHookBus;
use App\Services\CheckoutAbuseGuard;
use App\Services\PaymentService;
use App\Support\FakeConsumerData;
use App\Support\PixCheckoutDisplay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommerceCheckoutPaymentService
{
    public function process(Request $request, CommerceCheckoutSession $session, array $validated): RedirectResponse
    {
        if ($session->isExpired()) {
            return redirect()->back()->with('error', 'Sessão inválida ou expirada.');
        }

        $order = Order::with('product', 'orderItems')->find($session->order_id);
        if (! $order || $order->status !== 'pending') {
            return redirect()->back()->with('error', 'Pedido indisponível.');
        }

        $tenantId = (int) $session->tenant_id;
        $product = $order->product;
        $isPluginCheckout = CommerceCheckoutContextRegistry::supports($order);
        if ($product && ! $product->is_active && ! $isPluginCheckout) {
            return redirect()->back()->with('error', 'Produto indisponível.');
        }

        $registryGateway = CommerceCheckoutContextRegistry::resolveGatewayConfig($order);
        $config = is_array($product?->checkout_config) ? $product->checkout_config : [];
        $gatewayConfig = $registryGateway ?? (is_array($config['payment_gateways'] ?? null)
            ? $config['payment_gateways']
            : []);

        $method = $validated['payment_method'];
        $pg = $gatewayConfig;
        $methodAvailable = $this->methodAvailable($tenantId, $pg, $method);
        if (! $methodAvailable) {
            return redirect()->back()->with('error', 'Método de pagamento não disponível.');
        }

        $customer = is_array($session->customer) ? $session->customer : [];
        $email = (string) ($customer['email'] ?? $order->email ?? '');
        $name = trim((string) ($customer['name'] ?? '')) ?: $email;
        $rawDoc = preg_replace('/\D/', '', (string) ($customer['cpf'] ?? $order->cpf ?? ''));
        $fake = FakeConsumerData::getForGateway($session->id);
        $consumer = [
            'name' => $name ?: $fake['name'],
            'document' => strlen($rawDoc) >= 11 ? $rawDoc : $fake['document'],
            'email' => $email,
            'phone' => trim((string) ($customer['phone'] ?? $order->phone ?? '')),
        ];

        $amount = (float) $order->amount;
        $paymentService = app(PaymentService::class);

        if ($product) {
            $request->merge(['email' => $email, 'product_id' => $product->id, 'payment_method' => $method]);
            app(CheckoutAbuseGuard::class)->assertCanCreateCheckout($request, $product);
        }

        $pluginCheckoutData = PluginCheckoutExtensionRegistry::decodeCheckoutDataFromRequest($request);
        if ($product) {
            $beforeProcess = new CheckoutBeforeProcess($product, $validated, null, $pluginCheckoutData);
            PluginCheckoutExtensionRegistry::invokeProcessHandlers($product, $validated, $pluginCheckoutData, $beforeProcess);
            PluginHookBus::doAction('checkout.before_process', $beforeProcess, $product, $validated, $pluginCheckoutData);
            event($beforeProcess);
            if ($beforeProcess->abort !== null && $beforeProcess->abort !== '') {
                return redirect()->back()->with('error', $beforeProcess->abort);
            }
        }

        if ($method === 'pix') {
            try {
                event(new OrderPending($order));
                $result = $paymentService->createPixPayment($order, $product, $consumer, $gatewayConfig);
                event(new PixGenerated($order, [
                    'qrcode' => $result['qrcode'] ?? null,
                    'copy_paste' => $result['copy_paste'] ?? null,
                    'transaction_id' => $result['transaction_id'] ?? null,
                ]));
                $pixToken = PixCheckoutDisplay::persistAndStoreSession($order, $result, [
                    'amount' => $amount,
                    'product_name' => $this->productLabel($session),
                    'redirect_after_purchase' => route('checkout.thank-you', ['order_id' => $order->id]),
                ]);

                return redirect()->route('checkout.pix', ['token' => $pixToken]);
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX.');
            }
        }

        if ($method === 'boleto') {
            try {
                event(new OrderPending($order));
                $result = $paymentService->createBoletoPayment($order, $product, $consumer, $gatewayConfig);
                event(new BoletoGenerated($order, [
                    'amount' => $result['amount'] ?? $amount,
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? null,
                    'pdf_url' => $result['pdf_url'] ?? null,
                ]));
                $boletoToken = Str::random(32);
                session()->put('boleto_display.'.$boletoToken, [
                    'order_id' => $order->id,
                    'amount_formatted' => 'R$ '.number_format($result['amount'] ?? $amount, 2, ',', '.'),
                    'expire_at' => $result['expire_at'] ?? null,
                    'barcode' => $result['barcode'] ?? '',
                    'pdf_url' => $result['pdf_url'] ?? null,
                    'product_name' => $this->productLabel($session),
                    'redirect_after_purchase' => route('checkout.thank-you', ['order_id' => $order->id]),
                    'customer_name' => $name,
                    'customer_email' => $email,
                    'customer_phone' => $customer['phone'] ?? null,
                ]);

                return redirect()->route('checkout.boleto', ['token' => $boletoToken]);
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível gerar o boleto.');
            }
        }

        if ($method === 'card') {
            $card = [
                'payment_token' => $validated['payment_token'],
                'card_mask' => $validated['card_mask'] ?? null,
                'return_url' => route('checkout.thank-you', ['order_id' => $order->id]),
            ];
            try {
                event(new OrderPending($order));
                $cardGatewayConfig = $gatewayConfig;
                $cardGatewayConfig['card_redundancy'] = [];
                $result = $paymentService->createCardPayment($order, $product, $consumer, $card, $cardGatewayConfig);
                $status = strtolower((string) ($result['status'] ?? 'pending'));
                $isApproved = in_array($status, ['paid', 'settled', 'approved', 'completed'], true);
                if ($isApproved) {
                    $order->update(['status' => 'completed']);
                    event(new OrderCompleted($order));

                    return redirect()->route('checkout.thank-you', ['order_id' => $order->id]);
                }

                return redirect()->back()->with('error', 'Pagamento recusado ou pendente.');
            } catch (\Throwable $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: 'Não foi possível processar o cartão.');
            }
        }

        return redirect()->back()->with('error', 'Método não suportado.');
    }

    private function productLabel(CommerceCheckoutSession $session): string
    {
        $order = Order::find($session->order_id);
        if ($order) {
            $label = CommerceCheckoutContextRegistry::resolveOrderLabel($order);
            if ($label !== null && $label !== '') {
                return $label;
            }
        }

        $items = is_array($session->line_items) ? $session->line_items : [];
        if (count($items) > 1) {
            return count($items).' itens';
        }

        return (string) ($items[0]['name'] ?? 'Pedido');
    }

    /**
     * @param  array<string, mixed>  $pg
     */
    private function methodAvailable(int $tenantId, array $pg, string $method): bool
    {
        $methods = \App\Support\CheckoutPaymentMethodsBuilder::build($tenantId, $pg, null);
        $ids = \App\Support\CheckoutPaymentMethodsBuilder::methodIds($methods);

        return in_array($method, $ids, true);
    }
}
