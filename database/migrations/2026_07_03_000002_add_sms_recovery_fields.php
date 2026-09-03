<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checkout_sessions') && ! Schema::hasColumn('checkout_sessions', 'phone')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $table->string('phone', 32)->nullable()->after('name');
                $table->unsignedTinyInteger('recovery_sms_stage')->default(0)->after('recovery_email_next_at');
                $table->timestamp('recovery_sms_last_sent_at')->nullable()->after('recovery_sms_stage');
                $table->timestamp('recovery_sms_next_at')->nullable()->after('recovery_sms_last_sent_at');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'recovery_sms_stage')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedTinyInteger('recovery_sms_stage')->default(0)->after('recovery_email_next_at');
                $table->timestamp('recovery_sms_last_sent_at')->nullable()->after('recovery_sms_stage');
                $table->timestamp('recovery_sms_next_at')->nullable()->after('recovery_sms_last_sent_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('checkout_sessions')) {
            Schema::table('checkout_sessions', function (Blueprint $table) {
                $cols = ['phone', 'recovery_sms_stage', 'recovery_sms_last_sent_at', 'recovery_sms_next_at'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('checkout_sessions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['recovery_sms_stage', 'recovery_sms_last_sent_at', 'recovery_sms_next_at'] as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
