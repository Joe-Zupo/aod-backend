<?php

namespace App\Http\Resources;

use App\Models\CommEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One entry on a participant's timeline. Currently every timestamp is a
 * communication event with its callouts; the `type` tag is here so other
 * timestamp kinds (game events, manual markers) can join the same list later.
 * `annotations` carries any system annotation on the callout (game-state
 * alignment today) and is always present, empty when there is none.
 *
 * @mixin CommEvent
 */
class TimelineTimestampResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => 'communication_event',
            'id' => $this->id,
            'communication_type' => $this->communication_type,
            'is_redundant' => $this->is_redundant,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'content' => $this->content,
            'reviewed' => $this->reviewed_at !== null,
            'reviewed_at' => $this->reviewed_at,
            'reviewed_by' => $this->reviewed_by,
            'created_by' => $this->created_by,
            'callouts' => CalloutDetectionResource::collection($this->whenLoaded('calloutDetections')),
            'annotations' => TimelineAnnotationResource::tree(
                $this->whenLoaded('annotations', fn () => $this->annotations, new Collection),
            ),
        ];
    }
}
