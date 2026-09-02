<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Subscription;
use App\Services\CajuPaySubscriptionService;
use App\Services\SubscriptionLifecycleService;
use App\Services\TeamAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssinaturasController extends Controller
{
    public function index(Request $request, SubscriptionLifecycleService $lifecycle): Response
    {
        $tenantId = auth()->user()->tenant_id;

        // Garante past_due / webhook mesmo se o cron estiver atrasado (ao abrir o painel).
        $lifecycle->processStaleForTenant($tenantId);

        $statusFilter = $request->query('status', 'all');
        if (! in_array($statusFilter, ['all', 'active', 'past_due', 'cancelled'], true)) {
            $statusFilter = 'all';
        }

        $baseQuery = Subscription::with(['user', 'product', 'subscriptionPlan'])
            ->forTenant($tenantId);

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $baseQuery->whereIn('product_id', $allowed ?: ['__none__']);
        }

        $stats = $this->buildStats(clone $baseQuery);
        $cajuService = app(CajuPaySubscriptionService::class);

        $query = clone $baseQuery;
        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        $assinaturas = $query->orderByDesc('subscriptions.current_period_end')
            ->paginate(20)
            ->withQueryString()
            ->through(function ($s) use ($lifecycle, $cajuService) {
                $effectiveStatus = $lifecycle->effectiveStatus($s);
                $isCaju = $cajuService->isCajuPayManaged($s);

                return [
                    'id' => $s->id,
                    'user' => $s->user ? ['id' => $s->user->id, 'name' => $s->user->name, 'email' => $s->user->email] : null,
                    'product' => $s->product ? ['id' => $s->product->id, 'name' => $s->product->name] : null,
                    'plan' => $s->subscriptionPlan ? [
                        'id' => $s->subscriptionPlan->id,
                        'name' => $s->subscriptionPlan->name,
                        'interval' => $s->subscriptionPlan->interval,
                        'interval_label' => \App\Models\SubscriptionPlan::intervalLabels()[$s->subscriptionPlan->interval] ?? $s->subscriptionPlan->interval,
                        'price' => (float) $s->subscriptionPlan->price,
                    ] : null,
                    'current_period_start' => $s->current_period_start?->toDateString(),
                    'current_period_end' => $s->current_period_end?->toDateString(),
                    'access_until' => $lifecycle->accessUntil($s)?->toDateString(),
                    'renewable_until' => $lifecycle->renewableUntil($s)?->toDateString(),
                    'days_until_end' => $lifecycle->daysUntilPeriodEnd($s),
                    'status' => $s->status,
                    'effective_status' => $effectiveStatus,
                    'renewal_url' => url('/renovar/'.$s->renewal_token),
                    'gateway_subscription_id' => $s->gateway_subscription_id,
                    'gateway_label' => $isCaju ? 'CajuPay' : ($s->gateway_subscription_id ? 'PIX automático' : 'Local'),
                    'is_cajupay' => $isCaju,
                ];
            });

        return Inertia::render('Assinaturas/Index', [
            'stats' => $stats,
            'statusFilter' => $statusFilter,
            'assinaturas' => $assinaturas,
        ]);
    }

    public function show(Subscription $subscription, CajuPaySubscriptionService $cajuService, SubscriptionLifecycleService $lifecycle): JsonResponse
    {
        if (! $this->authorizeSubscription($subscription)) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $isCaju = $cajuService->isCajuPayManaged($subscription);
        $charges = [];
        $remote = null;
        $remoteError = null;

        if ($isCaju) {
            try {
                $remote = $cajuService->getRemote($subscription);
                $charges = $cajuService->mapChargesForPanel($cajuService->listCharges($subscription));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('AssinaturasController show: falha ao consultar CajuPay', [
                    'subscription_id' => $subscription->id,
                    'message' => $e->getMessage(),
                ]);
                $remoteError = 'Não foi possível consultar cobranças na CajuPay agora. Os dados locais da assinatura foram carregados.';
            }
        } else {
            $orders = Order::query()
                ->where('user_id', $subscription->user_id)
                ->where('product_id', $subscription->product_id)
                ->where('subscription_plan_id', $subscription->subscription_plan_id)
                ->orderByDesc('id')
                ->limit(20)
                ->get(['id', 'status', 'amount', 'currency', 'gateway', 'is_renewal', 'created_at', 'gateway_id']);

            $charges = $orders->map(static function (Order $o): array {
                return [
                    'id' => (string) $o->id,
                    'status' => (string) $o->status,
                    'amount_cents' => (int) round(((float) $o->amount) * 100),
                    'due_at' => null,
                    'paid_at' => $o->status === 'completed' ? $o->created_at?->toIso8601String() : null,
                    'payment_id' => $o->gateway_id,
                    'is_local_order' => true,
                ];
            })->all();
        }

        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'effective_status' => $lifecycle->effectiveStatus($subscription),
                'user' => $subscription->user ? [
                    'id' => $subscription->user->id,
                    'name' => $subscription->user->name,
                    'email' => $subscription->user->email,
                ] : null,
                'product' => $subscription->product ? [
                    'id' => $subscription->product->id,
                    'name' => $subscription->product->name,
                ] : null,
                'plan' => $subscription->subscriptionPlan ? [
                    'id' => $subscription->subscriptionPlan->id,
                    'name' => $subscription->subscriptionPlan->name,
                    'interval_label' => \App\Models\SubscriptionPlan::intervalLabels()[$subscription->subscriptionPlan->interval] ?? $subscription->subscriptionPlan->interval,
                    'price' => (float) $subscription->subscriptionPlan->price,
                ] : null,
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'gateway_subscription_id' => $subscription->gateway_subscription_id,
                'is_cajupay' => $isCaju,
                'remote_status' => is_array($remote) ? ($remote['status'] ?? null) : null,
                'remote_error' => $remoteError,
                'charges' => $charges,
            ],
        ]);
    }

    public function cancel(Request $request, Subscription $subscription, SubscriptionLifecycleService $lifecycle, CajuPaySubscriptionService $cajuService): JsonResponse
    {
        if (! $this->authorizeSubscription($subscription)) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        if ($subscription->status === Subscription::STATUS_CANCELLED) {
            return response()->json(['success' => false, 'message' => 'Esta assinatura já está cancelada.'], 422);
        }

        $validated = $request->validate([
            'revoke_access_now' => ['sometimes', 'boolean'],
        ]);

        $revokeNow = (bool) ($validated['revoke_access_now'] ?? false);

        if ($cajuService->isCajuPayManaged($subscription)) {
            try {
                $cajuService->cancelRemote($subscription);
            } catch (\Throwable $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'Não foi possível cancelar a assinatura na CajuPay.',
                ], 422);
            }
        }

        $lifecycle->cancelSubscription($subscription, revokeAccessNow: $revokeNow);

        return response()->json([
            'success' => true,
            'message' => $revokeNow
                ? 'Assinatura cancelada e acesso revogado.'
                : 'Assinatura cancelada. O acesso permanece até o fim da carência, se houver.',
        ]);
    }

    public function sync(Subscription $subscription, CajuPaySubscriptionService $cajuService): JsonResponse
    {
        if (! $this->authorizeSubscription($subscription)) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        if (! $cajuService->isCajuPayManaged($subscription)) {
            return response()->json(['success' => false, 'message' => 'Sync disponível apenas para assinaturas CajuPay.'], 422);
        }

        try {
            $result = $cajuService->syncRemote($subscription);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Falha ao sincronizar com a CajuPay.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sincronização enviada à CajuPay.',
            'result' => $result,
        ]);
    }

    public function refundCharge(Request $request, Subscription $subscription, string $chargeId, CajuPaySubscriptionService $cajuService): JsonResponse
    {
        if (! $this->authorizeSubscription($subscription)) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        if (! $cajuService->isCajuPayManaged($subscription)) {
            return response()->json(['success' => false, 'message' => 'Reembolso de parcela disponível apenas para CajuPay.'], 422);
        }

        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            return response()->json(['success' => false, 'message' => 'Cobrança inválida.'], 422);
        }

        try {
            $result = $cajuService->refundCharge($subscription, $chargeId);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível reembolsar a cobrança.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reembolso da parcela solicitado na CajuPay.',
            'result' => $result,
        ]);
    }

    public function retryCharge(Subscription $subscription, string $chargeId, CajuPaySubscriptionService $cajuService): JsonResponse
    {
        if (! $this->authorizeSubscription($subscription)) {
            return response()->json(['success' => false, 'message' => 'Assinatura não encontrada.'], 404);
        }

        if (! $cajuService->isCajuPayManaged($subscription)) {
            return response()->json(['success' => false, 'message' => 'Retry disponível apenas para CajuPay.'], 422);
        }

        $chargeId = trim($chargeId);
        if ($chargeId === '') {
            return response()->json(['success' => false, 'message' => 'Cobrança inválida.'], 422);
        }

        try {
            $result = $cajuService->retryCharge($subscription, $chargeId);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível retentar a cobrança.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Retry da cobrança enviado à CajuPay.',
            'result' => $result,
        ]);
    }

    private function authorizeSubscription(Subscription $subscription): bool
    {
        $tenantId = auth()->user()->tenant_id;
        if ($subscription->tenant_id !== $tenantId) {
            return false;
        }

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            if (! in_array($subscription->product_id, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Subscription>  $baseQuery
     * @return array{ativas: int, past_due: int, canceladas: int, clientes: int, mrr: float}
     */
    private function buildStats($baseQuery): array
    {
        $ativas = (clone $baseQuery)->where('status', Subscription::STATUS_ACTIVE)->count();
        $pastDue = (clone $baseQuery)->where('status', Subscription::STATUS_PAST_DUE)->count();
        $canceladas = (clone $baseQuery)->where('status', Subscription::STATUS_CANCELLED)->count();
        $clientes = (clone $baseQuery)->where('status', Subscription::STATUS_ACTIVE)->distinct('user_id')->count('user_id');

        $mrrQuery = Subscription::query()
            ->where('subscriptions.tenant_id', auth()->user()->tenant_id)
            ->where('subscriptions.status', Subscription::STATUS_ACTIVE)
            ->join('subscription_plans', 'subscriptions.subscription_plan_id', '=', 'subscription_plans.id')
            ->where('subscription_plans.interval', '!=', 'lifetime');

        if (auth()->user()?->isTeam()) {
            $allowed = app(TeamAccessService::class)->allowedProductIdsFor(auth()->user());
            $mrrQuery->whereIn('subscriptions.product_id', $allowed ?: ['__none__']);
        }

        $mrr = round((float) $mrrQuery->sum('subscription_plans.price'), 2);

        return [
            'ativas' => $ativas,
            'past_due' => $pastDue,
            'canceladas' => $canceladas,
            'clientes' => $clientes,
            'mrr' => $mrr,
        ];
    }
}
