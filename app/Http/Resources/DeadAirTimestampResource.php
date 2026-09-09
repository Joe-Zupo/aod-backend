<?php

namespace App\Http\Resources;

use App\Models\DeadAirPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One dead-air period on the session timeline: an interval, so it carries real
 * `start_ms` / `end_ms` / `duration_ms` (unlike a point-in-time game event) and
 * an `annotations` array, always present, `[]` unless a game event went uncalled
 * inside it. The session-level sibling of GameEventTimestampResource; the two
 * are merged into one `game_events` spine (see
 * docs/adr/0009-dead-air-detection.md).
 *
 * @mixin DeadAirPeriod
 */
class DeadAirTimestampResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => 'dead_air',
            'id' => $this->id,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'duration_ms' => $this->end_ms - $this->start_ms,
            'annotations' => $this->whenLoaded(
                'annotations',
                fn () => TimelineAnnotationResource::collection($this->annotations),
                [],
            ),
        ];
    }
}
