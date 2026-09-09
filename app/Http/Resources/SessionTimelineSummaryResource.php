<?php

namespace App\Http\Resources;

use App\Models\Session;
use App\Models\User;
use App\Support\TimelineMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The provisional session timeline-summary payload: communication frequency,
 * event counts by type, a redundant tally, and a team-aggregate talk / silence
 * proxy expressed as percentages of the session window. All of it provisional
 * pending the Dead Air milestone (see docs/adr/0006-communication-events.md).
 *
 * Expects `participants.aodRecord.transcript.commEvents.annotations`,
 * `participants.user`, `gameEvents` and `deadAirPeriods` loaded.
 *
 * @mixin Session
 */
class SessionTimelineSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $recording = $this->participants->filter(
            fn ($participant) => $participant->aodRecord?->transcript !== null,
        );

        $windowMs = (int) $recording
            ->map(fn ($participant) => (int) $participant->aodRecord->transcript->audio_duration_ms)
            ->max();

        $allEvents = $recording->flatMap(fn ($participant) => $participant->aodRecord->transcript->commEvents);

        // silenceProxy returns raw milliseconds; the endpoint reports them as
        // percentages of the window (see the *_percentage fields below).
        [$totalTalkMs, $totalSilenceMs, $longestSilenceMs] = TimelineMetrics::silenceProxy($allEvents, $windowMs);

        // The review progress figure is coach-only, and only while the timeline
        // is still under review (at analysis_ready it is 100% by definition).
        $showReview = $this->status === Session::STATUS_TIMELINE_READY
            && ($user = $request->user()) !== null
            && in_array($user->teamRole($this->team), User::TEAM_COACH_ROLES, true);

        // Stash the shared window (and whether to show review) on each
        // participant so the per-player scope resource can read them through a
        // standard constructor.
        $recording->each(function ($participant) use ($windowMs, $showReview) {
            $participant->setAttribute('summary_window_ms', $windowMs);
            $participant->setAttribute('summary_show_review', $showReview);
        });

        $teamReview = [];

        if ($showReview) {
            $counts = $this->resource->reviewCounts();
            $teamReview = ['review' => [
                'reviewed' => $counts['reviewed'],
                'total' => $counts['total'],
                'complete' => $counts['reviewed'] === $counts['total'],
            ]];
        }

        return [
            'session_id' => $this->id,
            // The analysis window: the longest recording in the session
            // (max transcript audio_duration_ms), which under the zero-offset
            // assumption bounds the whole timeline. Denominator for the
            // frequency and *_percentage figures.
            'session_window_ms' => $windowMs,
            'team' => [
                'frequency_per_min' => TimelineMetrics::frequencyPerMin($allEvents->count(), $windowMs),
                'comm_event_count' => TimelineMetrics::commEventCounts($allEvents),
                'redundant_count' => TimelineMetrics::redundantCount($allEvents),
                'game_events' => TimelineMetrics::gameEventCounts($this->gameEvents),
                'alignment' => TimelineMetrics::alignmentCounts($allEvents),
                'dead_air' => TimelineMetrics::deadAirCounts($this->deadAirPeriods),
                // Percentages of the session window, via TimelineMetrics::percentageOfWindow:
                //   talk_percentage            = total_talk_ms     / session_window, as a percent
                //   silence_percentage         = total_silence_ms  / session_window, as a percent
                //   longest_silence_percentage = longest_silence_ms / session_window, as a percent
                // talk and silence are rounded independently, so they only sum to ~100.
                'talk_percentage' => TimelineMetrics::percentageOfWindow($totalTalkMs, $windowMs),
                'silence_percentage' => TimelineMetrics::percentageOfWindow($totalSilenceMs, $windowMs),
                'longest_silence_percentage' => TimelineMetrics::percentageOfWindow($longestSilenceMs, $windowMs),
                ...$teamReview,
            ],
            'participants' => TimelineSummaryParticipantResource::collection($recording->values()),
        ];
    }
}
