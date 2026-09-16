<?php

use App\Support\CompleteStrandedParticipants;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Correct participants stranded by ADR 0014's redefinition of
     * `participant_status`. The rule lives in the class so it can be tested;
     * see CompleteStrandedParticipantsTest.
     *
     * There is no down(): the earlier statuses are not recoverable, and the
     * rows this touches were describing a session that had already ended.
     */
    public function up(): void
    {
        (new CompleteStrandedParticipants)();
    }
};
