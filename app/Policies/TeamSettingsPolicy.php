<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeamSettingsPolicy
{
    public function view(User $user, TeamSettings $settings): Response
    {
        return $this->isActiveCoach($user, $settings->team);
    }

    public function update(User $user, TeamSettings $settings): Response
    {
        return $this->isActiveCoach($user, $settings->team);
    }

    /**
     * Configuring detection settings is coaching work: any active Coach, main or
     * assistant, may view or update it. A user with no active membership is
     * denied as not found, matching TeamPolicy::view's outsider handling.
     */
    private function isActiveCoach(User $user, Team $team): Response
    {
        $role = $user->teamRole($team);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, User::TEAM_COACH_ROLES, true)
            ? Response::allow()
            : Response::deny();
    }
}
