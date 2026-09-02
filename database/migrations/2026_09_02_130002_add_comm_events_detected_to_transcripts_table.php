<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set once DetectCommEvents has run for a completed transcript, including
     * the zero-hit case where no comm_events row is written. This is the marker
     * AdvanceSessionAfterProcessing's fan-in checks: a completed transcript
     * counts as done only once this is true (see
     * docs/adr/0006-communication-events.md).
     */
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table) {
            $table->boolean('comm_events_detected')->default(false)->after('audio_duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table) {
            $table->dropColumn('comm_events_detected');
        });
    }
};
