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
        $settings = $team->ensureSettings();

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
        // Authorized by UpdateTeamSettingsRequest::authorize(), which runs
        // before validation — so an unauthorized caller is denied regardless
        // of whether their payload is well-formed.
        $team = $request->user()->resolveTeam($request);
        $settings = $team->ensureSettings();

        $settings->get(TeamSettings::SETTING_DEAD_AIR_THRESHOLD)->update([
            'setting_parameter' => $request->validated('dead_air_threshold_ms'),
        ]);

        return $this->success('Team settings updated.', [
            'settings' => new TeamSettingsResource($settings),
        ]);
    }

    /**
     * Authorize against an unsaved TeamSettings so a denied caller never causes
     * a settings row to be created as a side effect of ensureSettings() below.
     */
    private function authorizeSettings(string $ability, Team $team): void
    {
        $this->authorize($ability, TeamSettings::unsavedFor($team));
    }
}
