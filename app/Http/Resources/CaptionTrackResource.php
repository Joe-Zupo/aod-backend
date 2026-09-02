<?php

namespace App\Http\Resources;

use App\Models\SessionParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One caption track: a participant who recorded audio, their transcript status,
 * and the transcript's sentences (with nested words) when it completed. A
 * failed or empty transcript yields the same shape with an empty sentence list.
 *
 * @mixin SessionParticipant
 */
class CaptionTrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $transcript = $this->aodRecord->transcript;

        return [
            'participant_id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->whenLoaded('user', fn () => $this->user->username),
            'status' => $transcript->status,
            'language_code' => $transcript->language_code,
            'confidence' => $transcript->confidence,
            'audio_duration_ms' => $transcript->audio_duration_ms,
            'sentences' => CaptionSentenceResource::collection($transcript->sentences),
        ];
    }
}
