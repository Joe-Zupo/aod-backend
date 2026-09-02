<?php

namespace App\Http\Resources;

use App\Models\CommEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry on a participant's timeline. Currently every timestamp is a
 * communication event with its callouts; the `type` tag is here so other
 * timestamp kinds (game events, manual markers) can join the same list later.
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
            'callouts' => CalloutDetectionResource::collection($this->whenLoaded('calloutDetections')),
        ];
    }
}
