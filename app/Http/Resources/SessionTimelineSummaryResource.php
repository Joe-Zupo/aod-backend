<?php

namespace App\Http\Resources;

use App\Models\Session;
use App\Support\TimelineMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The provisional session timeline-summary payload: communication frequency,
 * event counts by type, a redundant tally, and a team-aggregate talk / silence
 * proxy expressed as percentages of the session window. All of it provisional
 * pending the Dead Air milestone (see docs/adr/0006-communication-events.md).
 *
 * Expects `participants.aodRecord.transcript.commEvents` and
 * `participants.user` loaded.
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

        // Stash the shared window on each participant so the per-player scope
        // resource can read it through a standard constructor.
        $recording->each->setAttribute('summary_window_ms', $windowMs);

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
                // Percentages of the session window, via TimelineMetrics::percentageOfWindow:
                //   talk_percentage            = total_talk_ms     / session_window, as a percent
                //   silence_percentage         = total_silence_ms  / session_window, as a percent
                //   longest_silence_percentage = longest_silence_ms / session_window, as a percent
                // talk and silence are rounded independently, so they only sum to ~100.
                'talk_percentage' => TimelineMetrics::percentageOfWindow($totalTalkMs, $windowMs),
                'silence_percentage' => TimelineMetrics::percentageOfWindow($totalSilenceMs, $windowMs),
                'longest_silence_percentage' => TimelineMetrics::percentageOfWindow($longestSilenceMs, $windowMs),
            ],
            'participants' => TimelineSummaryParticipantResource::collection($recording->values()),
        ];
    }
}
