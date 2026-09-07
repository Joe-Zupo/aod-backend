<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set when AssessGameStateAlignment has run for a session, cleared by
     * reanalyze(). The session-level marker AdvanceSessionAfterProcessing's
     * fan-in checks: a session with game events reaches `timeline_ready` only
     * once this is set (see docs/adr/0008-game-state-alignment.md). Session
     * level, not per-transcript, since alignment is one pass over the whole
     * session.
     */
    public function up(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->timestamp('game_alignment_assessed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('app_sessions', function (Blueprint $table) {
            $table->dropColumn('game_alignment_assessed_at');
        });
    }
};
