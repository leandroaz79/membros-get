<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\User;
use App\Services\UserProductAccessService;
use Tests\TestCase;

class UserProductAccessServiceTest extends TestCase
{
    public function test_user_owns_product_with_string_and_int_id_candidates(): void
    {
        $product = $this->createTestProduct(['name' => 'Owned']);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product->users()->attach($user->id);

        $service = app(UserProductAccessService::class);

        $this->assertTrue($service->userOwnsProduct($user, $product->id));
        $this->assertTrue($service->userOwnsProduct($user, (string) $product->id));
        if (is_numeric($product->id)) {
            $this->assertTrue($service->userOwnsProduct($user, (int) $product->id));
        }
    }

    public function test_owned_product_id_set_matches_has_owned_product_id(): void
    {
        $product = $this->createTestProduct(['name' => 'Set test']);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product->users()->attach($user->id);

        $service = app(UserProductAccessService::class);
        $set = $service->ownedProductIdSet($user);

        $this->assertTrue($service->hasOwnedProductId($set, $product->id));
        $this->assertFalse($service->hasOwnedProductId($set, '999999'));
    }

    public function test_resolve_access_for_member_area_product(): void
    {
        $slug = 'curso'.substr(md5(uniqid('', true)), 0, 6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product->users()->attach($user->id);

        $access = app(UserProductAccessService::class)->resolveAccess($user, $product);

        $this->assertNotNull($access);
        $this->assertSame('member_area', $access['action']);
        $this->assertStringContainsString('/m/'.$slug, $access['url']);
    }

    public function test_resolve_member_area_related_open_url_for_link_product(): void
    {
        $hostSlug = 'hub'.substr(md5(uniqid('', true)), 0, 6);
        $host = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $hostSlug,
        ]);
        $link = $this->createTestProduct([
            'type' => Product::TYPE_LINK,
            'checkout_config' => array_replace_recursive(Product::defaultCheckoutConfig(), [
                'deliverable_link' => 'https://example.com/file',
            ]),
        ]);

        $open = app(UserProductAccessService::class)->resolveMemberAreaRelatedOpenUrl(
            $host,
            $link,
            'http://localhost/m/'.$hostSlug
        );

        $this->assertNotNull($open);
        $this->assertStringContainsString('/products/'.$link->id.'/deliverable', $open['url']);
    }
}
