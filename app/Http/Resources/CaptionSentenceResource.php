<?php

namespace App\Http\Resources;

use App\Models\TranscriptSentence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TranscriptSentence */
class CaptionSentenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->position,
            'text' => $this->text,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'confidence' => $this->confidence,
            'words' => CaptionWordResource::collection($this->whenLoaded('words')),
        ];
    }
}
