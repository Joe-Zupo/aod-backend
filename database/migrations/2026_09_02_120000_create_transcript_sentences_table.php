<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sentence-level breakdown of one transcript, as segmented by
     * AssemblyAI's GET /v2/transcript/{id}/sentences endpoint. One row per
     * sentence in transcript order, each with its file-relative millisecond
     * span and an aggregate confidence. The frontend caption display is built
     * on these. Rebuilt wholesale by FetchTranscriptSentences on a re-run (see
     * docs/adr/0005-sentence-indexed-transcript-storage.md).
     */
    public function up(): void
    {
        Schema::create('transcript_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_id')->constrained('transcripts')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->longText('text');
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->float('confidence');

            $table->index(['transcript_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_sentences');
    }
};
