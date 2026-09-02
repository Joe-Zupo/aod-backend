<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Team Keyword hit inside a Communication Event: the matched word row,
     * the keyword and Category snapshot at detection time, its millisecond span
     * and the model confidence. A later Team Settings edit does not change a
     * row already stored (see docs/adr/0006-communication-events.md).
     */
    public function up(): void
    {
        Schema::create('callout_detections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comm_event_id')->constrained('comm_events')->cascadeOnDelete();
            $table->foreignId('transcript_word_id')->constrained('transcript_words')->cascadeOnDelete();
            $table->string('keyword');
            $table->string('normalized_keyword');
            $table->string('category', 16);
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->float('confidence');

            $table->index(['comm_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callout_detections');
    }
};
