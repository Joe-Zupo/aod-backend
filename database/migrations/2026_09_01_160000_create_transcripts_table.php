<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One transcript per stored AOD. Created queued by the session completion
     * call, then carried to a terminal state by the transcription jobs (see
     * docs/adr/0004-transcription-pipeline.md). The word-level detail lives in
     * transcript_words.
     */
    public function up(): void
    {
        Schema::create('transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aod_record_id')->unique()->constrained('aod_records')->cascadeOnDelete();
            $table->string('provider')->default('assemblyai');
            $table->string('provider_transcript_id')->nullable();
            $table->string('status', 16)->default('queued');
            $table->longText('text')->nullable();
            $table->string('language_code')->nullable();
            $table->float('confidence')->nullable();
            $table->unsignedBigInteger('audio_duration_ms')->nullable();
            $table->string('error')->nullable();
            $table->json('raw_response')->nullable();
            // Bounds the self-rescheduling poll: incremented on every poll,
            // compared against services.assemblyai.max_polls to time out a job
            // that never leaves the provider's processing state.
            $table->unsignedInteger('poll_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcripts');
    }
};
