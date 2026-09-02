<?php

namespace App\Http\Resources;

use App\Models\TranscriptWord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TranscriptWord */
class CaptionWordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'position' => $this->sentence_position,
            'text' => $this->word,
            'start_ms' => $this->start_ms,
            'end_ms' => $this->end_ms,
            'confidence' => $this->confidence,
        ];
    }
}
