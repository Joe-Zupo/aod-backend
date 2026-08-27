<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class TeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'user_code' => $this->user_code,
            'is_online' => $this->is_online,
            'member_role' => $this->pivot->member_role,
            'status' => $this->pivot->status,
            'joined_at' => $this->pivot->joined_at,
        ];
    }
}
