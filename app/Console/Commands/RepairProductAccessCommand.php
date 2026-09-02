<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\UserProductAccessService;
use Illuminate\Console\Command;

class RepairProductAccessCommand extends Command
{
    protected $signature = 'access:repair {--order= : Repair a single order by ID} {--dry-run : List repairs without applying them}';

    protected $description = 'Re-grant product access for completed orders missing product_user pivots';

    public function handle(UserProductAccessService $accessService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orderId = $this->option('order');

        $query = Order::query()
            ->where('status', 'completed')
            ->whereNotNull('user_id')
            ->with(['product', 'orderItems.product']);

        if ($orderId !== null && $orderId !== '') {
            $query->whereKey($orderId);
        }

        $repaired = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(100, function ($orders) use ($accessService, $dryRun, &$repaired, &$skipped) {
            foreach ($orders as $order) {
                if (! $this->orderNeedsRepair($order, $accessService)) {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $this->line("Would repair order #{$order->id} (user {$order->user_id})");
                    $repaired++;

                    continue;
                }

                $order->grantPurchasedProductAccessToBuyer();
                $this->info("Repaired access for order #{$order->id}");
                $repaired++;
            }
        });

        $this->info($dryRun
            ? "Dry run: {$repaired} order(s) would be repaired, {$skipped} OK."
            : "Done: {$repaired} order(s) repaired, {$skipped} already OK.");

        return self::SUCCESS;
    }

    private function orderNeedsRepair(Order $order, UserProductAccessService $accessService): bool
    {
        $userId = $order->user_id;
        if (! $userId) {
            return false;
        }

        $productIds = [];
        if ($order->product_id) {
            $productIds[] = $order->product_id;
        }
        foreach ($order->orderItems as $item) {
            if ($item->product_id) {
                $productIds[] = $item->product_id;
            }
        }

        $order->loadMissing('subscriptionPlan', 'productOffer');
        if ($order->subscription_plan_id && $order->subscriptionPlan) {
            $comboProductIds = $order->subscriptionPlan->combo_product_ids ?? [];
        } elseif ($order->product_offer_id && $order->productOffer) {
            $comboProductIds = $order->productOffer->combo_product_ids ?? [];
        } elseif ($order->product) {
            $comboProductIds = $order->product->combo_product_ids ?? [];
        } else {
            $comboProductIds = [];
        }

        foreach ($comboProductIds as $comboProductId) {
            if ($comboProductId && $comboProductId !== $order->product_id) {
                $productIds[] = $comboProductId;
            }
        }

        $productIds = array_values(array_unique(array_filter($productIds)));
        if ($productIds === []) {
            return false;
        }

        $user = $order->user;
        if (! $user) {
            return false;
        }

        foreach ($productIds as $productId) {
            if (! $accessService->userOwnsProduct($user, $productId)) {
                return true;
            }
        }

        return false;
    }
}
