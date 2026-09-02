<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\User;
use Tests\TestCase;

class PanelPushSubscribeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_push_subscribe_accepts_long_endpoint(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.str_repeat('a', 600);

        $response = $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'AAA+/=', 'p256dh' => 'BBB+/='],
            'renewed' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('panel_push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => $endpoint,
        ]);
    }

    public function test_push_subscribe_stores_valid_subscription(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $endpoint = 'https://example.com/push/valid-endpoint';

        $response = $this->actingAs($user)->postJson('/painel/push-subscribe', [
            'endpoint' => $endpoint,
            'keys' => ['auth' => 'CCC+/=', 'p256dh' => 'DDD+/='],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('subscribed', true);

        $this->assertDatabaseHas('panel_push_subscriptions', [
            'endpoint' => $endpoint,
            'tenant_id' => $user->tenant_id,
        ]);
    }

    public function test_push_subscribe_rejects_unauthenticated(): void
    {
        $this->postJson('/painel/push-subscribe', [
            'endpoint' => 'https://example.com/push/x',
            'keys' => ['auth' => 'A', 'p256dh' => 'B'],
        ])->assertUnauthorized();
    }
}
