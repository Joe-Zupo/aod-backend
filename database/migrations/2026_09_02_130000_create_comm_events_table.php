<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Communication Event per cluster of Team Keyword hits within
     * Communication Event Padding of one another in a single player's
     * transcript. `communication_type` is derived from the Categories of the
     * cluster's hits, `is_redundant` from a repeated Category or keyword.
     * `padding_ms` is the setting value snapshot at detection time. Rebuilt
     * wholesale by DetectCommEvents on a re-run (see
     * docs/adr/0006-communication-events.md).
     */
    public function up(): void
    {
        Schema::create('comm_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_id')->constrained('transcripts')->cascadeOnDelete();
            $table->string('communication_type', 16);
            $table->boolean('is_redundant')->default(false);
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->text('content');
            $table->unsignedInteger('padding_ms');
            $table->timestamps();

            $table->index(['transcript_id', 'start_ms']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comm_events');
    }
};
