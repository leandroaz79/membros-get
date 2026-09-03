<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\IntegraxConnection;
use App\Models\TeamRole;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntegraxControllerTest extends TestCase
{
    public function test_authorized_user_can_save_integrax_token(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($user)->putJson('/integracoes/integrax', [
            'api_token' => 'my-secret-token',
            'is_active' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('connection.configured', true)
            ->assertJsonPath('connection.is_active', true);

        $this->assertDatabaseHas('integrax_connections', [
            'tenant_id' => 1,
            'is_active' => 1,
        ]);
    }

    public function test_team_user_without_integracoes_permission_cannot_update(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $role = TeamRole::create([
            'tenant_id' => 1,
            'name' => 'Sem integrações',
            'permissions' => [
                'dashboard.view' => true,
                'integracoes.view' => false,
            ],
        ]);

        $team = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => $owner->tenant_id,
            'team_role_id' => $role->id,
        ]);

        $response = $this->actingAs($team)->putJson('/integracoes/integrax', [
            'api_token' => 'blocked',
        ]);

        $response->assertStatus(403);
    }

    public function test_test_endpoint_sends_sms_when_configured(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        Http::fake([
            'sms.aresfun.com/*' => Http::response(['success' => true], 200),
        ]);

        $user = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        IntegraxConnection::create([
            'tenant_id' => 1,
            'api_token' => 'live-token',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->postJson('/integracoes/integrax/test', [
            'phone' => '11999998888',
            'message' => 'Teste',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
    }
}
