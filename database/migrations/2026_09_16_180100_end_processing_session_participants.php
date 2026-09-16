<?php

use App\Support\EndProcessingParticipants;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Move participants of sessions already `processing` to `ending`, the
     * status ADR 0015 adds before `completed`. The rule lives in the class so
     * it can be tested; see EndProcessingParticipantsTest.
     *
     * There is no down(): the fan-in completes these rows when their session
     * reaches `timeline_ready`, so a rollback has no single state to restore.
     */
    public function up(): void
    {
        (new EndProcessingParticipants)();
    }
};
