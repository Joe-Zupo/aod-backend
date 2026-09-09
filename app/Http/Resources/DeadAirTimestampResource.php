<?php

namespace App\Http\Resources;

use App\Models\DeadAirPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
            'reviewed' => $this->reviewed_at !== null,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewed_by,
            'created_by' => $this->created_by,
            'annotations' => TimelineAnnotationResource::tree(
                $this->whenLoaded('annotations', fn () => $this->annotations, new Collection),
            ),
        ];
    }
}
