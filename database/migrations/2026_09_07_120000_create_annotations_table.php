<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared home for system and (later) human annotations on the timeline.
     * Polymorphic `annotatable`, so a row can hang off a communication event
     * (game-state alignment, ADR 0008) or a dead-air period (ADR 0009), and
     * coach / player notes extend it rather than add a table. `user_id` null
     * means system-generated (timeline management will let a coach or player
     * author rows later). `assessment` (`possibly_positive` /
     * `possibly_negative` / `neutral`) is set only for the
     * `game_state_alignment` topic; `game_event_ids` is the events that drove
     * the body; `alignment_window_ms` snapshots the window a
     * `game_state_alignment` row was assessed under, null for other topics.
     */
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->morphs('annotatable');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 32);
            $table->string('assessment', 32)->nullable();
            $table->text('body');
            $table->json('game_event_ids');
            $table->unsignedInteger('alignment_window_ms')->nullable();
            $table->timestamps();

            $table->index('topic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
