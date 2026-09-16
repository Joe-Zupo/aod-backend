<?php

namespace App\Support;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Support\Facades\DB;

/**
 * Complete every participant left behind by the ADR 0014 redefinition of
 * `participant_status`.
 *
 * Before that change, completion swept only the `recording` set, so a Coach
 * stayed at `ready` and a player who stopped early stayed at `needs_consent`
 * for the life of the session. Sessions that finished under the old rule still
 * carry those rows.
 *
 * Only participants still in the session are corrected. A departed row already
 * says what happened through `left_at`, and a cancelled session's participants
 * were departed by `cancel()`, so they are excluded for free rather than by a
 * rule of their own.
 *
 * Written as a class rather than inline in the migration so the rule can be
 * tested against rows a test builds, which a migration running under
 * RefreshDatabase cannot be.
 */
class CompleteStrandedParticipants
{
    /**
     * Sessions past the point of recording. `cancelled` is deliberately absent.
     */
    private const FINISHED_STATUSES = [
        Session::STATUS_PROCESSING,
        Session::STATUS_TIMELINE_READY,
        Session::STATUS_ANALYSIS_READY,
    ];

    /**
     * @return int the number of rows corrected
     */
    public function __invoke(): int
    {
        return DB::table('session_participants')
            ->whereNull('left_at')
            ->where('participant_status', '!=', SessionParticipant::PARTICIPANT_STATUS_COMPLETED)
            ->whereIn('session_id', DB::table('app_sessions')
                ->select('id')
                ->whereIn('status', self::FINISHED_STATUSES))
            ->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED]);
    }
}
