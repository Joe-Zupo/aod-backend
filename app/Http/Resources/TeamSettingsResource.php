<?php

namespace App\Http\Resources;

use App\Models\TeamSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Wraps the Collection<string, TeamSettings> returned by Team::ensureSettings(),
 * keyed by setting_name, into the combined team-settings shape the API exposes.
 *
 * @mixin Collection<string, TeamSettings>
 */
class TeamSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deadAirThreshold = $this->resource->get(TeamSettings::SETTING_DEAD_AIR_THRESHOLD);
        $informative = $this->resource->get(TeamSettings::SETTING_INFORMATIVE_KEYWORDS);
        $declarative = $this->resource->get(TeamSettings::SETTING_DECLARATIVE_KEYWORDS);

        return [
            'team_id' => $deadAirThreshold->team_id,
            'dead_air_threshold_ms' => $deadAirThreshold->setting_parameter,
            'informative_keywords' => $informative->keywords->pluck('keyword'),
            'declarative_keywords' => $declarative->keywords->pluck('keyword'),
            'updated_at' => $deadAirThreshold->updated_at,
        ];
    }
}
