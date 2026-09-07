<?php

namespace App\Http\Resources;

use App\Models\SessionParticipant;
use App\Support\TimelineMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One player's slice of the timeline summary: their communication frequency,
 * event counts by type, and redundant tally over the session window. The
 * window is read from a transient `summary_window_ms` attribute the parent
 * resource stashes on each participant so every player's numbers share one
 * denominator.
 *
 * @mixin SessionParticipant
 */
class TimelineSummaryParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $events = $this->aodRecord->transcript->commEvents;
        $windowMs = (int) $this->summary_window_ms;

        return [
            'participant_id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->whenLoaded('user', fn () => $this->user->username),
            'frequency_per_min' => TimelineMetrics::frequencyPerMin($events->count(), $windowMs),
            'comm_event_count' => TimelineMetrics::commEventCounts($events),
            'redundant_count' => TimelineMetrics::redundantCount($events),
            'alignment' => TimelineMetrics::alignmentCounts($events),
        ];
    }
}
