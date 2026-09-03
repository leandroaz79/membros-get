<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop first to handle the case of a partial table from a failed prior run
        Schema::dropIfExists('pixel_x_integration_product');

        Schema::create('pixel_x_integration_product', function (Blueprint $table) {
            $table->unsignedBigInteger('pixel_x_integration_id');
            $table->string('product_id', 36);

            $table->foreign('pixel_x_integration_id')
                ->references('id')
                ->on('pixel_x_integrations')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            // Explicit short name — auto-generated name exceeds MySQL's 64-char limit
            $table->unique(['pixel_x_integration_id', 'product_id'], 'pxip_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_x_integration_product');
    }
};
