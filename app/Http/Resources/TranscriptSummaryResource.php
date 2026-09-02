<?php

namespace App\Http\Resources;

use App\Models\Transcript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The transcript metadata carried alongside a timeline participant: status and
 * language / confidence / duration, no sentences or words. The captions
 * endpoint is where the full transcript body lives.
 *
 * @mixin Transcript
 */
class TranscriptSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'language_code' => $this->language_code,
            'confidence' => $this->confidence,
            'audio_duration_ms' => $this->audio_duration_ms,
        ];
    }
}
