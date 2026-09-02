<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('session_token', 64)->unique();
            $table->string('customer_email')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('commerce_cart_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commerce_cart_id')->constrained('commerce_carts')->cascadeOnDelete();
            $table->string('product_id');
            $table->unsignedBigInteger('product_offer_id')->nullable();
            $table->unsignedBigInteger('subscription_plan_id')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_amount', 12, 2);
            $table->json('metadata')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['commerce_cart_id', 'position']);
        });

        Schema::create('commerce_checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('commerce_cart_id')->nullable()->constrained('commerce_carts')->nullOnDelete();
            $table->string('session_token', 64)->unique();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('BRL');
            $table->json('customer')->nullable();
            $table->json('line_items')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_checkout_sessions');
        Schema::dropIfExists('commerce_cart_lines');
        Schema::dropIfExists('commerce_carts');
    }
};
