<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties every transcript word to the sentence it belongs to. Words are now
     * ingested from the sentences response (see
     * docs/adr/0005-sentence-indexed-transcript-storage.md): the existing
     * transcript-order `position` stays, `sentence_position` is the word's
     * index within its sentence. Nullable so the column can be added over any
     * pre-existing rows; every write from FetchTranscriptSentences sets it.
     */
    public function up(): void
    {
        Schema::table('transcript_words', function (Blueprint $table) {
            $table->foreignId('transcript_sentence_id')
                ->nullable()
                ->after('transcript_id')
                ->constrained('transcript_sentences')
                ->cascadeOnDelete();
            $table->unsignedInteger('sentence_position')->nullable()->after('position');

            $table->index(['transcript_sentence_id', 'sentence_position']);
        });
    }

    public function down(): void
    {
        Schema::table('transcript_words', function (Blueprint $table) {
            $table->dropForeign(['transcript_sentence_id']);
            $table->dropIndex(['transcript_sentence_id', 'sentence_position']);
            $table->dropColumn(['transcript_sentence_id', 'sentence_position']);
        });
    }
};
