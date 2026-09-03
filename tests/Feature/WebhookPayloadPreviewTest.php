<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class WebhookPayloadPreviewTest extends TestCase
{
    public function test_payload_preview_returns_envelope_for_pedido_pago(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('integrations.webhooks.payload-preview', ['slug' => 'pedido_pago']));

        $response->assertOk()
            ->assertJsonPath('envelope.event', 'pedido_pago')
            ->assertJsonPath('envelope.event_label', 'Pedido pago')
            ->assertJsonPath('payload.customer.email', 'exemplo@email.com')
            ->assertJsonPath('payload.offer.public_id', 'B8BcHrY')
            ->assertJsonPath('payload.status', 'paid');
    }

    public function test_payload_preview_rejects_invalid_slug(): void
    {
        $user = User::factory()->create([
            'tenant_id' => 1,
            'role' => User::ROLE_INFOPRODUTOR,
        ]);

        $this->actingAs($user)
            ->getJson(route('integrations.webhooks.payload-preview', ['slug' => 'evento_inexistente']))
            ->assertStatus(422);
    }
}
