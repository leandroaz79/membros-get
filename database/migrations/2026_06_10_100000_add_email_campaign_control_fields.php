<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('sent_at');
            $table->text('last_error')->nullable()->after('paused_at');
        });

        Schema::table('email_campaign_sends', function (Blueprint $table) {
            $table->string('status', 20)->default('sent')->after('email');
            $table->text('error_message')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaign_sends', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_message']);
        });

        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'last_error']);
        });
    }
};
