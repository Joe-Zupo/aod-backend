<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session timeline payload: the session, its Timeline row, and one entry
 * per participant who has an AOD, each carrying that participant's own
 * communication-event timestamps ordered by start. Recording metadata only, no
 * URLs.
 *
 * Expects `timeline`, `participants.aodRecord.transcript.commEvents.calloutDetections`,
 * `participants.vodRecord` and `participants.user` loaded.
 *
 * @mixin Session
 */
class SessionTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->id,
            'status' => $this->status,
            'timeline' => [
                'id' => $this->timeline?->id,
                'created_at' => $this->timeline?->created_at,
                'duration_ms' => null,
            ],
            'participants' => TimelineParticipantResource::collection(
                $this->participants->filter(
                    fn ($participant) => $participant->aodRecord !== null,
                )->values(),
            ),
        ];
    }
}
