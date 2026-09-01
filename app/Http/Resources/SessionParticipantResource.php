<?php

namespace App\Http\Resources;

use App\Models\SessionParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SessionParticipant */
class SessionParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'username' => $this->whenLoaded('user', fn () => $this->user->username),
            'participant_role' => $this->participant_role,
            'participant_status' => $this->participant_status,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
        ];
    }
}
