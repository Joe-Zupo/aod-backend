<?php

namespace App\Http\Resources;

use App\Models\Session;
use App\Support\TimelineSpine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session timeline payload: the session, its Timeline row, a session-level
 * `game_events` spine, and one entry per participant who has an AOD, each
 * carrying that participant's own communication-event timestamps ordered by
 * start. Recording metadata only, no URLs.
 *
 * The `game_events` spine merges the point-in-time game events and the interval
 * dead-air periods, ordered by `start_ms`, ties putting a game_event first (see
 * docs/adr/0009-dead-air-detection.md). It keeps the name `game_events` for
 * backward compatibility even though it now holds both.
 *
 * Expects `timeline`, `participants.aodRecord.transcript.commEvents.calloutDetections`,
 * `participants.vodRecord`, `participants.user`, `gameEvents` and
 * `deadAirPeriods.annotations` loaded.
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
            // The session-wide spine the per-participant callouts are read
            // against: point-in-time game events and interval dead-air periods
            // merged, ordered by start_ms. Sits ahead of participants.
            'game_events' => TimelineSpine::merge(
                GameEventTimestampResource::collection($this->gameEvents)->toArray($request),
                DeadAirTimestampResource::collection($this->deadAirPeriods)->toArray($request),
            ),
            'participants' => TimelineParticipantResource::collection(
                $this->participants->filter(
                    fn ($participant) => $participant->aodRecord !== null,
                )->values(),
            ),
        ];
    }
}
