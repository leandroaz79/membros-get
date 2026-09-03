<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\Product;
use App\Models\ProductAffiliateProgram;
use App\Models\User;
use Tests\TestCase;

class AffiliateProgramPublicTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureInstalled::class);
    }

    public function test_disabled_program_shows_unavailable_page_instead_of_404(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'is_active' => true]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => false,
            'default_commission_percent' => 10,
            'manual_approval' => false,
            'public_slug' => 'madame-viral',
        ]);

        $response = $this->get('/afiliar/madame-viral');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Afiliar/Unavailable')
            ->where('slug', 'madame-viral')
            ->where('reason', 'disabled')
        );
    }

    public function test_enabled_program_shows_landing_page(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'is_active' => true]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => true,
            'default_commission_percent' => 15,
            'manual_approval' => false,
            'public_slug' => 'programa-ativo',
        ]);

        $response = $this->get('/afiliar/programa-ativo');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Afiliar/Show')
            ->where('slug', 'programa-ativo')
        );
    }

    public function test_program_api_omits_public_page_url_when_disabled(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => false,
            'default_commission_percent' => 10,
            'manual_approval' => true,
            'public_slug' => 'madame-viral',
        ]);

        $response = $this->actingAs($owner)->getJson('/produtos/'.$product->id.'/affiliate-program');

        $response->assertOk();
        $response->assertJsonPath('program.public_slug', 'madame-viral');
        $response->assertJsonPath('program.public_page_url', null);
    }

    public function test_update_program_persists_enabled_with_empty_support_email(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1, 'is_active' => true]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => false,
            'default_commission_percent' => 10,
            'manual_approval' => true,
            'public_slug' => 'madame-viral',
        ]);

        $response = $this->actingAs($owner)->putJson('/produtos/'.$product->id.'/affiliate-program', [
            'enabled' => true,
            'default_commission_percent' => 12,
            'manual_approval' => true,
            'share_buyer_data' => false,
            'public_slug' => 'madame-viral',
            'support_email' => '',
            'description' => '',
            'settlement_days_pix' => 0,
            'settlement_days_card' => 30,
            'settlement_days_boleto' => 2,
            'public_page_url' => null,
            'checkout_slug' => $product->checkout_slug,
            'id' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('program.enabled', true);

        $this->assertDatabaseHas('product_affiliate_programs', [
            'product_id' => $product->id,
            'enabled' => true,
        ]);
    }

    public function test_update_program_without_enabled_key_does_not_enable(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'tenant_id' => 1]);
        $product = $this->createTestProduct(['tenant_id' => 1]);

        ProductAffiliateProgram::create([
            'product_id' => $product->id,
            'enabled' => false,
            'default_commission_percent' => 10,
            'manual_approval' => true,
            'public_slug' => 'madame-viral',
        ]);

        $response = $this->actingAs($owner)->putJson('/produtos/'.$product->id.'/affiliate-program', [
            'default_commission_percent' => 12,
            'manual_approval' => true,
            'share_buyer_data' => false,
            'public_slug' => 'madame-viral',
        ]);

        $response->assertOk();
        $response->assertJsonPath('program.enabled', false);
    }
}
