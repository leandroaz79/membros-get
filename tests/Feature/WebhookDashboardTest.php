<?php

namespace Tests\Feature;

use App\Events\OrderCompleted;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use Tests\TestCase;

class WebhookDashboardTest extends TestCase
{
    public function test_dashboard_stats_aggregate_last_24_hours(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $webhook = Webhook::create([
            'tenant_id' => 1,
            'name' => 'Zapier',
            'url' => 'https://hooks.example.com/zap',
            'events' => [OrderCompleted::class],
            'is_active' => true,
        ]);

        WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'pedido_pago',
            'event_label' => 'Pedido pago',
            'request_payload' => ['event' => 'pedido_pago'],
            'response_status' => 200,
            'response_body' => 'ok',
            'success' => true,
            'source' => 'job',
            'created_at' => now()->subHours(2),
        ]);

        WebhookLog::create([
            'webhook_id' => $webhook->id,
            'event' => 'pedido_pago',
            'event_label' => 'Pedido pago',
            'request_payload' => ['event' => 'pedido_pago'],
            'response_status' => 500,
            'response_body' => 'error',
            'success' => false,
            'error_message' => 'HTTP 500',
            'source' => 'test',
            'created_at' => now()->subHours(1),
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('integrations.webhooks.dashboard-stats'));

        $response->assertOk()
            ->assertJsonPath('summary.sent', 2)
            ->assertJsonPath('summary.delivered', 1)
            ->assertJsonPath('summary.failed', 1)
            ->assertJsonPath('summary.delivery_rate', 50)
            ->assertJsonPath('webhooks.0.id', $webhook->id)
            ->assertJsonPath('webhooks.0.stats.sent', 2)
            ->assertJsonPath('webhooks.0.stats.success_rate', 50)
            ->assertJsonPath('webhooks.0.stats.test_count', 1)
            ->assertJsonPath('webhooks.0.stats.job_count', 1);

        $this->assertCount(24, $response->json('sparkline.sent'));
    }

    public function test_dashboard_stats_empty_when_no_webhooks(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $this->actingAs($user)
            ->getJson(route('integrations.webhooks.dashboard-stats'))
            ->assertOk()
            ->assertJsonPath('summary.sent', 0)
            ->assertJsonPath('webhooks', []);
    }
}
