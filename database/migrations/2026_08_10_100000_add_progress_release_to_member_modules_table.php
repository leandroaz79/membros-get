<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            $table->unsignedTinyInteger('release_progress_percent')->nullable()->after('release_at_date');
            $table->json('release_required_module_ids')->nullable()->after('release_progress_percent');
        });
    }

    public function down(): void
    {
        Schema::table('member_modules', function (Blueprint $table) {
            $table->dropColumn(['release_progress_percent', 'release_required_module_ids']);
        });
    }
};
