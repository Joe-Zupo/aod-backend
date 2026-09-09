<?php

namespace App\Http\Resources;

use App\Models\GameEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One game event on the session timeline. A point in time, so
 * `start_ms == end_ms == match_time_ms`. The session-level sibling of
 * TimelineTimestampResource, which carries per-participant communication
 * events (see docs/adr/0007-manual-game-event-ingest.md).
 *
 * @mixin GameEvent
 */
class GameEventTimestampResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => 'game_event',
            'id' => $this->id,
            'game_type' => $this->type,
            'side' => $this->side,
            'match_time_ms' => $this->match_time_ms,
            'start_ms' => $this->match_time_ms,
            'end_ms' => $this->match_time_ms,
            'round_number' => $this->round_number,
            'note' => $this->note,
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
