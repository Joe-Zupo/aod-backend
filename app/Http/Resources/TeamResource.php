<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Team */
class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_code' => $this->team_code,
            'team_name' => $this->team_name,
            'description' => $this->description,
            'disbanded_at' => $this->disbanded_at,
            'created_at' => $this->created_at,
            'members' => TeamMemberResource::collection($this->whenLoaded('activeMembers')),
        ];
    }
}
