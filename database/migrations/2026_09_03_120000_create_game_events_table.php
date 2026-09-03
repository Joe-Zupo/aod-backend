<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per game event supplied on session completion (kills, deaths,
     * spike plants/defuses, round outcomes). Hand-authored `source: manual`
     * for the prototype; a `riot` source writes the same table later. `side`
     * is null for round outcomes. `raw` keeps the original payload element.
     * Keyed to the session, matching how comm_events keys the transcript
     * rather than the timeline (see docs/adr/0007-manual-game-event-ingest.md).
     */
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('app_sessions')->cascadeOnDelete();
            $table->string('source', 16)->default('manual');
            $table->string('type', 16);
            $table->string('side', 8)->nullable();
            $table->unsignedBigInteger('match_time_ms');
            $table->unsignedInteger('round_number')->nullable();
            $table->string('note')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'match_time_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};
