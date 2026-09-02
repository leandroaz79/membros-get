<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHPUnit uses SQLite; 2026_03_09_100003 only alters orders on MySQL/MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return;
        }

        if (! Schema::hasColumn('orders', 'api_application_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('api_application_id')->nullable();
                $table->unsignedBigInteger('api_checkout_session_id')->nullable()->index();
            });
        } elseif (! Schema::hasColumn('orders', 'api_checkout_session_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('api_checkout_session_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            return;
        }

        if (Schema::hasColumn('orders', 'api_checkout_session_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('api_checkout_session_id');
            });
        }
        if (Schema::hasColumn('orders', 'api_application_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('api_application_id');
            });
        }
    }
};
