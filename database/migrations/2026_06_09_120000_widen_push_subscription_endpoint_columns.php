<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint', 2048)->change();
        });

        if (Schema::hasTable('member_push_subscriptions')) {
            Schema::table('member_push_subscriptions', function (Blueprint $table) {
                $table->string('endpoint', 2048)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('panel_push_subscriptions', function (Blueprint $table) {
            $table->string('endpoint', 500)->change();
        });

        if (Schema::hasTable('member_push_subscriptions')) {
            Schema::table('member_push_subscriptions', function (Blueprint $table) {
                $table->string('endpoint', 500)->change();
            });
        }
    }
};
