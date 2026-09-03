<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Tests\TestCase;

class RepairProductAccessCommandTest extends TestCase
{
    public function test_access_repair_command_grants_missing_pivots(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $main = $this->createTestProduct(['name' => 'Main repair']);
        $extra = $this->createTestProduct(['name' => 'Extra repair']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $main->id,
            'status' => 'completed',
            'amount' => 30,
            'email' => 'repair@test.com',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $main->id,
            'amount' => 10,
            'position' => 0,
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $extra->id,
            'amount' => 20,
            'position' => 1,
        ]);

        $this->assertFalse($extra->users()->where('users.id', $user->id)->exists());

        $this->artisan('access:repair', ['--order' => $order->id])
            ->assertSuccessful();

        $this->assertTrue($main->users()->where('users.id', $user->id)->exists());
        $this->assertTrue($extra->users()->where('users.id', $user->id)->exists());
    }

    public function test_access_repair_dry_run_does_not_grant(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'role' => User::ROLE_ALUNO]);
        $product = $this->createTestProduct(['name' => 'Dry run']);

        $order = Order::create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'status' => 'completed',
            'amount' => 10,
            'email' => 'dry@test.com',
        ]);

        $this->artisan('access:repair', ['--order' => $order->id, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertFalse($product->users()->where('users.id', $user->id)->exists());
    }
}
