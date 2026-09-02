<?php

namespace App\Console\Commands;

use App\Gateways\GatewayRegistry;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Support\PendingPaymentReconcileSchedule;
use Illuminate\Console\Command;

class ReconcilePendingPaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile-pending
                            {--limit=200 : Máximo de pedidos para checar por execução}
                            {--days=30 : Considerar pedidos criados nos últimos X dias}';

    protected $description = 'Reconfirma pagamentos pendentes no gateway e aprova automaticamente quando liquidado.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $days = max(1, (int) $this->option('days'));

        $orders = Order::query()
            ->where('status', 'pending')
            ->whereNotNull('gateway')
            ->where('gateway', '!=', '')
            ->whereNotNull('gateway_id')
            ->where('gateway_id', '!=', '')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $checked = 0;
        $paid = 0;
        $cancelled = 0;
        $expired = 0;

        foreach ($orders as $order) {
            if (PendingPaymentReconcileSchedule::shouldExpirePix($order)) {
                $this->expirePixOrder($order);
                $expired++;

                continue;
            }

            if (! PendingPaymentReconcileSchedule::isDue($order)) {
                continue;
            }

            $gatewaySlug = is_string($order->gateway) ? $order->gateway : '';
            $transactionId = is_string($order->gateway_id) ? $order->gateway_id : (string) $order->gateway_id;

            if ($gatewaySlug === '' || $transactionId === '') {
                continue;
            }

            $credential = GatewayCredential::forTenant($order->tenant_id)
                ->where('gateway_slug', $gatewaySlug)
                ->where('is_connected', true)
                ->first();

            if (! $credential) {
                continue;
            }

            $driver = GatewayRegistry::driver($gatewaySlug);
            if (! $driver) {
                continue;
            }

            $credentials = $credential->getDecryptedCredentials();
            if ($credentials === []) {
                continue;
            }

            $checked++;

            try {
                $apiStatus = $driver->getTransactionStatus($transactionId, $credentials);
            } catch (\Throwable) {
                $apiStatus = null;
            }

            PendingPaymentReconcileSchedule::markChecked($order);

            if ($apiStatus === 'paid') {
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.paid', 'paid', [
                    'source' => 'reconcile_pending',
                ]);
                $paid++;

                continue;
            }

            if ($apiStatus === 'cancelled') {
                ProcessPaymentWebhook::dispatchSync($gatewaySlug, $transactionId, 'order.cancelled', 'cancelled', [
                    'source' => 'reconcile_pending',
                ]);
                $cancelled++;
            }
        }

        $this->info("Checados: {$checked} | Pagos: {$paid} | Cancelados: {$cancelled} | Expirados: {$expired}");

        return self::SUCCESS;
    }

    private function expirePixOrder(Order $order): void
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $meta['cancelled_reason'] = 'reconcile_pix_expired';
        $meta['cancelled_at'] = now()->toIso8601String();
        $order->update([
            'status' => 'cancelled',
            'metadata' => $meta,
        ]);
    }
}
