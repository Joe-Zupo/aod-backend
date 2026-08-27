<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'user_code' => $this->user_code,
            'riot_id' => $this->riot_id,
            'is_online' => $this->is_online,
            'roles' => $this->whenLoaded('roles', fn () => $this->getRoleNames()->values()),
            'teams' => TeamResource::collection($this->whenLoaded('activeTeams')),
            'created_at' => $this->created_at,
        ];
    }
}
