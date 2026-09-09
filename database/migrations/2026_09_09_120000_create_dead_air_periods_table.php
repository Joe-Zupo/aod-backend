<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per team-aggregate silent stretch longer than the team's
     * `dead_air_threshold`, computed once per session by DetectDeadAir (see
     * docs/adr/0009-dead-air-detection.md). `dead_air_threshold_ms` is the
     * value used, snapshot on each row like `comm_events.padding_ms`. Keyed to
     * the session, matching how `game_events` and `comm_events` key their owner.
     * A period carries its `dead_air` annotation polymorphically through the
     * `annotations` table; there is no duration column, it is `end_ms - start_ms`.
     */
    public function up(): void
    {
        Schema::create('dead_air_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('app_sessions')->cascadeOnDelete();
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->unsignedInteger('dead_air_threshold_ms');
            $table->timestamps();

            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_air_periods');
    }
};
