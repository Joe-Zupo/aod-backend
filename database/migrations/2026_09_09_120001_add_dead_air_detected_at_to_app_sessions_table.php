<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when DetectDeadAir has run for a session, cleared by reanalyze().
     * The second session-level marker AdvanceSessionAfterProcessing's fan-in
     * checks alongside `game_alignment_assessed_at`: every session reaches
     * `timeline_ready` only once this is set (see
     * docs/adr/0009-dead-air-detection.md). Session level, not per-transcript,
     * since dead-air detection is one pass over the whole session.
     */
    public function up(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->timestamp('dead_air_detected_at')->nullable()->after('game_alignment_assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->dropColumn('dead_air_detected_at');
        });
    }
};
