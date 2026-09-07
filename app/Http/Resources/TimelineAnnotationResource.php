<?php

namespace App\Http\Resources;

use App\Models\Annotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One system annotation inline on a timeline entry: its topic, the assessment
 * (null for a narration-only or dead-air row), the generated body, and the
 * game-event ids that drove it (see docs/adr/0008-game-state-alignment.md).
 *
 * @mixin Annotation
 */
class TimelineAnnotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'topic' => $this->topic,
            'assessment' => $this->assessment,
            'body' => $this->body,
            'game_event_ids' => $this->game_event_ids,
        ];
    }
}
