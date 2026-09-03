<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_community_posts', function (Blueprint $table) {
            $table->string('video', 500)->nullable()->after('image');
            $table->string('media_aspect', 10)->nullable()->after('video');
        });
    }

    public function down(): void
    {
        Schema::table('member_community_posts', function (Blueprint $table) {
            $table->dropColumn(['video', 'media_aspect']);
        });
    }
};
