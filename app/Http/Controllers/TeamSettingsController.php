<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamSettingsRequest;
use App\Http\Resources\TeamSettingsResource;
use App\Models\Team;
use App\Models\TeamSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamSettingsController extends Controller
{
    /**
     * Team Settings Return
     *
     * Return the active team's detection settings (dead-air threshold). Restricted
     * to any active Coach, main or assistant.
     */
    public function show(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorizeSettings('view', $team);
        $settings = $this->resolveSettings($team);

        return $this->success('Team settings retrieved.', [
            'settings' => new TeamSettingsResource($settings),
        ]);
    }

    /**
     * Update Team Settings
     *
     * Update the active team's dead-air threshold. Restricted to any active
     * Coach, main or assistant.
     */
    public function update(UpdateTeamSettingsRequest $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorizeSettings('update', $team);
        $settings = $this->resolveSettings($team);

        $settings->update($request->validated());

        return $this->success('Team settings updated.', [
            'settings' => new TeamSettingsResource($settings),
        ]);
    }

    /**
     * Authorize against an unsaved TeamSettings so a denied caller never causes
     * a settings row to be created as a side effect of resolveSettings() below.
     */
    private function authorizeSettings(string $ability, Team $team): void
    {
        $settings = new TeamSettings(['team_id' => $team->id]);
        $settings->setRelation('team', $team);

        $this->authorize($ability, $settings);
    }

    /**
     * The team's settings row, self-healing for any team that predates this
     * feature (or otherwise lacks one), with the already-loaded team attached
     * so the policy doesn't need to re-fetch it.
     */
    private function resolveSettings(Team $team): TeamSettings
    {
        $settings = $team->settings()->firstOrCreate([], ['dead_air_threshold_ms' => 5000]);
        $settings->setRelation('team', $team);

        return $settings;
    }
}
