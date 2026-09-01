<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The word-level breakdown of one transcript: every token AssemblyAI
     * returned, in order, with its file-relative start/end in milliseconds and
     * the model's confidence. Overwritten wholesale when a transcript is
     * re-run.
     */
    public function up(): void
    {
        Schema::create('transcript_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_id')->constrained('transcripts')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('word');
            $table->unsignedBigInteger('start_ms');
            $table->unsignedBigInteger('end_ms');
            $table->float('confidence');

            $table->index(['transcript_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_words');
    }
};
