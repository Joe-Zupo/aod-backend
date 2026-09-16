<?php

namespace App\Support;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\DB;

/**
 * Move every participant of a session already `processing` from `completed`
 * back to `ending`, the status ADR 0015 holds them at until the timeline is
 * ready.
 *
 * Before that change, completion swept straight to `completed`. Sessions at
 * `timeline_ready` or `analysis_ready` already hold the right value, and the
 * fan-in completes the corrected rows when their session gets there.
 *
 * Only participants still in the session are corrected. A departed row was not
 * swept by completion, and `left_at` already says what happened.
 *
 * Written as a class rather than inline in the migration so the rule can be
 * tested against rows a test builds, as CompleteStrandedParticipants is.
 */
class EndProcessingParticipants
{
    /**
     * @return int the number of rows corrected
     */
    public function __invoke(): int
    {
        return DB::table('session_participants')
            ->whereNull('left_at')
            ->where('participant_status', SessionParticipant::PARTICIPANT_STATUS_COMPLETED)
            ->whereIn('session_id', DB::table('app_sessions')
                ->select('id')
                ->where('status', Session::STATUS_PROCESSING))
            ->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING]);
    }
}
