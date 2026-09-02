<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One stored player audio recording (AOD) per Session Participant. Written
     * only by the session completion endpoint, in the same transaction that
     * moves the Session to completed (see docs/adr/0003-session-recording-storage.md).
     */
    public function up(): void
    {
        Schema::create('aod_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_participant_id')->unique()->constrained('session_participants')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aod_records');
    }
};
