<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            if (! Schema::hasColumn('member_modules', 'access_duration_days')) {
                $table->unsignedInteger('access_duration_days')->nullable()->after('release_at_date');
            }
        });

        Schema::table('member_lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('member_lessons', 'access_duration_days')) {
                $column = $table->unsignedInteger('access_duration_days')->nullable();
                if (Schema::hasColumn('member_lessons', 'release_at_date')) {
                    $column->after('release_at_date');
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            if (Schema::hasColumn('member_modules', 'access_duration_days')) {
                $table->dropColumn('access_duration_days');
            }
        });

        Schema::table('member_lessons', function (Blueprint $table) {
            if (Schema::hasColumn('member_lessons', 'access_duration_days')) {
                $table->dropColumn('access_duration_days');
            }
        });
    }
};
