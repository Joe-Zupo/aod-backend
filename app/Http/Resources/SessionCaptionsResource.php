<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The session captions payload: one caption track per participant who recorded
 * audio. Expects `participants.aodRecord.transcript.sentences.words` loaded.
 *
 * @mixin Session
 */
class SessionCaptionsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'session_id' => $this->id,
            'tracks' => CaptionTrackResource::collection(
                $this->participants->filter(
                    fn ($participant) => $participant->aodRecord && $participant->aodRecord->transcript,
                )->values(),
            ),
        ];
    }
}
