<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pixel_x_integration_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pixel_x_integration_id')
                ->constrained('pixel_x_integrations')
                ->cascadeOnDelete();
            $table->string('event', 255);
            $table->string('event_label', 255)->nullable();
            $table->json('request_payload')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->boolean('success');
            $table->string('error_message', 1024)->nullable();
            $table->string('source', 32)->default('job'); // 'test' | 'job'
            $table->timestamp('created_at')->nullable();

            $table->index(['pixel_x_integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixel_x_integration_logs');
    }
};
