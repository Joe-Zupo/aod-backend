<?php

namespace App\Http\Resources;

use App\Models\SessionParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One participant on the session timeline: who they are, their recording
 * metadata, and their own communication-event timestamps ordered by start.
 * The timeline groups timestamps under the participant who produced them
 * rather than as one flat cross-team list.
 *
 * Expects `aodRecord.transcript.commEvents.calloutDetections`, `vodRecord` and
 * `user` loaded.
 *
 * @mixin SessionParticipant
 */
class TimelineParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $transcript = $this->aodRecord->transcript;
        $events = $transcript ? $transcript->commEvents->sortBy('start_ms')->values() : collect();

        return [
            'participant_id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->whenLoaded('user', fn () => $this->user->username),
            'transcript' => $transcript ? new TranscriptSummaryResource($transcript) : null,
            'aod' => new RecordingMetaResource($this->aodRecord),
            'vod' => $this->vodRecord ? new RecordingMetaResource($this->vodRecord) : null,
            'timestamps' => TimelineTimestampResource::collection($events),
        ];
    }
}
