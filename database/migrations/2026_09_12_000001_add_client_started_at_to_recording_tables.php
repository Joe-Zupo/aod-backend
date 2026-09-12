<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aod_records', function (Blueprint $table) {
            $table->timestamp('client_started_at', 3)->nullable()->after('size_bytes');
        });

        Schema::table('vod_records', function (Blueprint $table) {
            $table->timestamp('client_started_at', 3)->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('aod_records', function (Blueprint $table) {
            $table->dropColumn('client_started_at');
        });

        Schema::table('vod_records', function (Blueprint $table) {
            $table->dropColumn('client_started_at');
        });
    }
};
