<?php

namespace App\Services;

use App\Models\Webhook;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WebhookDashboardService
{
    /**
     * @return array{summary: array<string, mixed>, sparkline: array<string, array<int, int>>, webhooks: list<array<string, mixed>>}
     */
    public function forTenant(int $tenantId): array
    {
        $since = now()->subHours(24);
        $webhooks = Webhook::forTenant($tenantId)->orderBy('name')->get(['id', 'name']);

        if ($webhooks->isEmpty()) {
            return $this->emptyResponse();
        }

        $webhookIds = $webhooks->pluck('id');

        $logs = WebhookLog::query()
            ->whereIn('webhook_id', $webhookIds)
            ->where('created_at', '>=', $since)
            ->get(['webhook_id', 'success', 'source', 'created_at']);

        $summary = $this->buildSummary($logs);
        $sparkline = $this->buildSparkline($logs, $since);

        $statsByWebhook = $this->buildStatsByWebhookId($logs);

        $webhookRows = $webhooks->map(function (Webhook $webhook) use ($statsByWebhook) {
            $stats = $statsByWebhook[$webhook->id] ?? $this->emptyWebhookStats();

            return [
                'id' => $webhook->id,
                'stats' => $stats,
            ];
        })->values()->all();

        return [
            'summary' => $summary,
            'sparkline' => $sparkline,
            'webhooks' => $webhookRows,
        ];
    }

    /**
     * @return array{summary: array<string, mixed>, sparkline: array<string, array<int, int>>, webhooks: list<array<string, mixed>>}
     */
    private function emptyResponse(): array
    {
        $since = now()->subHours(24);
        $emptySparkline = $this->buildSparkline(collect(), $since);

        return [
            'summary' => [
                'sent' => 0,
                'delivered' => 0,
                'failed' => 0,
                'delivery_rate' => 0.0,
            ],
            'sparkline' => $emptySparkline,
            'webhooks' => [],
        ];
    }

    /**
     * @param  Collection<int, WebhookLog>  $logs
     * @return array{sent: int, delivered: int, failed: int, delivery_rate: float}
     */
    private function buildSummary(Collection $logs): array
    {
        $sent = $logs->count();
        $delivered = $logs->where('success', true)->count();
        $failed = $sent - $delivered;

        return [
            'sent' => $sent,
            'delivered' => $delivered,
            'failed' => $failed,
            'delivery_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : 0.0,
        ];
    }

    /**
     * @param  Collection<int, WebhookLog>  $logs
     * @return array{sent: list<int>, delivered: list<int>, failed: list<int>}
     */
    private function buildSparkline(Collection $logs, Carbon $since): array
    {
        $buckets = [
            'sent' => array_fill(0, 24, 0),
            'delivered' => array_fill(0, 24, 0),
            'failed' => array_fill(0, 24, 0),
        ];

        $start = $since->copy()->startOfHour();
        $now = now();

        foreach ($logs as $log) {
            $created = $log->created_at;
            if (! $created || $created->lt($since)) {
                continue;
            }
            $hourIndex = (int) $start->diffInHours($created->copy()->startOfHour(), false);
            if ($hourIndex < 0 || $hourIndex > 23) {
                continue;
            }
            $buckets['sent'][$hourIndex]++;
            if ($log->success) {
                $buckets['delivered'][$hourIndex]++;
            } else {
                $buckets['failed'][$hourIndex]++;
            }
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, WebhookLog>  $logs
     * @return array<int, array<string, mixed>>
     */
    private function buildStatsByWebhookId(Collection $logs): array
    {
        $grouped = $logs->groupBy('webhook_id');
        $out = [];

        foreach ($grouped as $webhookId => $webhookLogs) {
            $out[(int) $webhookId] = $this->statsFromLogs($webhookLogs);
        }

        return $out;
    }

    /**
     * @param  Collection<int, WebhookLog>  $logs
     * @return array<string, mixed>
     */
    private function statsFromLogs(Collection $logs): array
    {
        $sent = $logs->count();
        $delivered = $logs->where('success', true)->count();
        $failed = $sent - $delivered;
        $lastLog = $logs->sortByDesc('created_at')->first();

        return [
            'sent' => $sent,
            'delivered' => $delivered,
            'failed' => $failed,
            'success_rate' => $sent > 0 ? round(($delivered / $sent) * 100, 1) : 0.0,
            'last_sent_at' => $lastLog?->created_at?->toIso8601String(),
            'test_count' => $logs->where('source', 'test')->count(),
            'job_count' => $logs->where('source', 'job')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyWebhookStats(): array
    {
        return [
            'sent' => 0,
            'delivered' => 0,
            'failed' => 0,
            'success_rate' => 0.0,
            'last_sent_at' => null,
            'test_count' => 0,
            'job_count' => 0,
        ];
    }
}
