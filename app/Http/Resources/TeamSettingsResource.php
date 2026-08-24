<?php

namespace App\Http\Resources;

use App\Models\TeamSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TeamSettings */
class TeamSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'team_id' => $this->team_id,
            'dead_air_threshold_ms' => $this->dead_air_threshold_ms,
            'updated_at' => $this->updated_at,
        ];
    }
}
