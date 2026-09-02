<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\TeamRole;
use App\Models\User;
use App\Support\VapidKeysManager;
use Tests\TestCase;

class PushVapidSettingsTest extends TestCase
{
    public function test_authorized_user_can_generate_vapid_keys(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        $this->mock(VapidKeysManager::class, function ($mock) {
            $mock->shouldReceive('generate')
                ->once()
                ->with(false)
                ->andReturn([
                    'success' => true,
                    'configured' => true,
                    'public_key' => 'BHxTestPublicKeyExample',
                    'message' => 'Chaves VAPID geradas e salvas com sucesso.',
                ]);
        });

        $response = $this->actingAs($user)->postJson('/configuracoes/push/vapid/generate', [
            'force' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'configured' => true,
                'public_key' => 'BHxTestPublicKeyExample',
            ]);

        $json = $response->json();
        $this->assertArrayNotHasKey('private_key', $json);
        $this->assertStringNotContainsString('private', strtolower(json_encode($json)));
    }

    public function test_team_user_without_permission_cannot_generate_vapid_keys(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $owner = User::factory()->create([
            'role' => User::ROLE_INFOPRODUTOR,
            'tenant_id' => 1,
        ]);

        $role = TeamRole::create([
            'tenant_id' => 1,
            'name' => 'Sem configurações',
            'permissions' => [
                'dashboard.view' => true,
                'vendas.view' => false,
                'produtos.view' => false,
                'relatorios.view' => false,
                'integracoes.view' => false,
                'email_marketing.view' => false,
                'api_pagamentos.view' => false,
                'configuracoes.view' => false,
                'equipe.manage' => false,
            ],
        ]);

        $team = User::factory()->create([
            'role' => User::ROLE_TEAM,
            'tenant_id' => $owner->tenant_id,
            'team_role_id' => $role->id,
        ]);

        $response = $this->actingAs($team)->postJson('/configuracoes/push/vapid/generate');

        $response->assertStatus(403);
    }

    public function test_generate_returns_error_when_env_not_writable(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $user = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR]);

        $this->mock(VapidKeysManager::class, function ($mock) {
            $mock->shouldReceive('generate')
                ->once()
                ->with(false)
                ->andReturn([
                    'success' => false,
                    'message' => 'Sem permissão para gravar o arquivo .env. Rode php artisan pwa:vapid no servidor.',
                    'error' => 'env_not_writable',
                ]);
        });

        $response = $this->actingAs($user)->postJson('/configuracoes/push/vapid/generate');

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
            ]);
    }
}
