<?php

namespace App\Http\Resources;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Session */
class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'created_by' => $this->created_by,
            'session_name' => $this->session_name,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'participants' => SessionParticipantResource::collection($this->whenLoaded('activeParticipants')),
        ];
    }
}
