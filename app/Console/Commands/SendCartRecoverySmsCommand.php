<?php

namespace App\Console\Commands;

use App\Jobs\SendCheckoutSessionRecoverySmsJob;
use App\Jobs\SendPendingOrderRecoverySmsJob;
use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendCartRecoverySmsCommand extends Command
{
    protected $signature = 'checkout:send-cart-recovery-sms {--limit=200 : Máximo por tipo por execução}';

    protected $description = 'Envia SMS de recuperação de carrinho conforme etapas configuradas por produto.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sessionsSent = $this->processCheckoutSessions($limit);
        $ordersSent = $this->processPendingOrders($limit);
        $this->info("Recovery SMS queued. Sessions={$sessionsSent} Orders={$ordersSent}");

        return self::SUCCESS;
    }

    private function shouldDispatchSync(): bool
    {
        $default = (string) config('queue.default', 'sync');
        if ($default === 'sync') {
            return true;
        }
        $heartbeat = Cache::get('queue_heartbeat');
        if (! is_string($heartbeat) || $heartbeat === '') {
            return true;
        }
        try {
            $last = \Illuminate\Support\Carbon::parse($heartbeat);
        } catch (\Throwable) {
            return true;
        }

        return $last->lt(now()->subMinutes(3));
    }

    private function processCheckoutSessions(int $limit): int
    {
        $now = now();
        $dispatchSync = $this->shouldDispatchSync();

        $sessions = CheckoutSession::query()
            ->with('product')
            ->whereIn('step', [CheckoutSession::STEP_FORM_STARTED, CheckoutSession::STEP_FORM_FILLED])
            ->whereNull('order_id')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where(function ($qq) use ($now) {
                $qq->whereNull('recovery_sms_next_at')
                    ->orWhere('recovery_sms_next_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $queued = 0;
        foreach ($sessions as $session) {
            $product = $session->product;
            $config = $this->recoveryConfig($product);
            if ($config === null) {
                continue;
            }

            $stages = $config['stages'];
            $maxStages = count($stages);
            $currentStage = (int) ($session->recovery_sms_stage ?? 0);
            if ($currentStage >= $maxStages) {
                continue;
            }

            $anchor = $session->form_filled_at ?? $session->form_started_at ?? $session->created_at;
            if ($this->pastDeadline($anchor, $config['deadline_hours'])) {
                continue;
            }

            $nextIndex = $currentStage;
            $delayMinutes = max(0, (int) ($stages[$nextIndex]['delay_minutes'] ?? 0));
            $dueAt = $anchor ? $anchor->copy()->addMinutes($delayMinutes) : $now;
            if ($dueAt->gt($now)) {
                $session->update(['recovery_sms_next_at' => $dueAt]);
                continue;
            }

            if ($dispatchSync) {
                SendCheckoutSessionRecoverySmsJob::dispatchSync($session->id, $nextIndex);
            } else {
                SendCheckoutSessionRecoverySmsJob::dispatch($session->id, $nextIndex);
            }

            $session->update([
                'recovery_sms_stage' => $currentStage + 1,
                'recovery_sms_last_sent_at' => $now,
                'recovery_sms_next_at' => $this->computeNextAt($anchor, $stages, $currentStage + 1),
            ]);
            $queued++;
        }

        Log::info('SendCartRecoverySmsCommand: sessions queued', ['count' => $queued]);

        return $queued;
    }

    private function processPendingOrders(int $limit): int
    {
        $now = now();
        $dispatchSync = $this->shouldDispatchSync();

        $orders = Order::query()
            ->with('product')
            ->where('status', 'pending')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->whereIn('metadata->checkout_payment_method', ['pix', 'boleto', 'pix_auto'])
            ->where(function ($qq) use ($now) {
                $qq->whereNull('recovery_sms_next_at')
                    ->orWhere('recovery_sms_next_at', '<=', $now);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $queued = 0;
        foreach ($orders as $order) {
            $product = $order->product;
            $config = $this->recoveryConfig($product);
            if ($config === null) {
                continue;
            }

            $stages = $config['stages'];
            $maxStages = count($stages);
            $currentStage = (int) ($order->recovery_sms_stage ?? 0);
            if ($currentStage >= $maxStages) {
                continue;
            }

            $anchor = $order->created_at;
            if ($this->pastDeadline($anchor, $config['deadline_hours'])) {
                continue;
            }

            $nextIndex = $currentStage;
            $delayMinutes = max(0, (int) ($stages[$nextIndex]['delay_minutes'] ?? 0));
            $dueAt = $anchor ? $anchor->copy()->addMinutes($delayMinutes) : $now;
            if ($dueAt->gt($now)) {
                $order->update(['recovery_sms_next_at' => $dueAt]);
                continue;
            }

            if ($dispatchSync) {
                SendPendingOrderRecoverySmsJob::dispatchSync($order->id, $nextIndex);
            } else {
                SendPendingOrderRecoverySmsJob::dispatch($order->id, $nextIndex);
            }

            $order->update([
                'recovery_sms_stage' => $currentStage + 1,
                'recovery_sms_last_sent_at' => $now,
                'recovery_sms_next_at' => $this->computeNextAt($anchor, $stages, $currentStage + 1),
            ]);
            $queued++;
        }

        Log::info('SendCartRecoverySmsCommand: orders queued', ['count' => $queued]);

        return $queued;
    }

    /**
     * @return array{deadline_hours: int, stages: array<int, array<string, mixed>>}|null
     */
    private function recoveryConfig(?Product $product): ?array
    {
        if (! $product) {
            return null;
        }

        $sms = is_array($product->checkout_config['sms'] ?? null) ? $product->checkout_config['sms'] : [];
        $recovery = is_array($sms['cart_recovery'] ?? null) ? $sms['cart_recovery'] : [];
        if (empty($recovery['enabled'])) {
            return null;
        }

        $stages = array_values(array_filter(
            is_array($recovery['stages'] ?? null) ? $recovery['stages'] : [],
            fn ($s) => is_array($s) && trim((string) ($s['body_text'] ?? '')) !== ''
        ));

        if ($stages === []) {
            return null;
        }

        return [
            'deadline_hours' => max(1, (int) ($recovery['deadline_hours'] ?? 48)),
            'stages' => $stages,
        ];
    }

    private function pastDeadline($anchor, int $deadlineHours): bool
    {
        if (! $anchor) {
            return false;
        }

        return $anchor->copy()->addHours($deadlineHours)->lt(now());
    }

    /**
     * @param  array<int, array<string, mixed>>  $stages
     */
    private function computeNextAt($anchor, array $stages, int $nextIndex): ?\Illuminate\Support\Carbon
    {
        if (! $anchor || ! isset($stages[$nextIndex])) {
            return null;
        }

        return $anchor->copy()->addMinutes(max(0, (int) ($stages[$nextIndex]['delay_minutes'] ?? 0)));
    }
}
