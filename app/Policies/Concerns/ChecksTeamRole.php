<?php

namespace App\Policies\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Shared "is this user an active member/coach of this team" checks, used by
 * every team-scoped policy (Team, TeamSettings, Session). A user with no
 * active membership is denied as not found, keeping team existence hidden
 * from outsiders; an active member with the wrong role is denied outright.
 */
trait ChecksTeamRole
{
    protected function isActiveMember(User $user, Team $team): Response
    {
        return $user->teamRole($team) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    protected function isActiveCoach(User $user, Team $team): Response
    {
        $role = $user->teamRole($team);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, User::TEAM_COACH_ROLES, true)
            ? Response::allow()
            : Response::deny();
    }

    protected function isMainCoach(User $user, Team $team): Response
    {
        $role = $user->teamRole($team);

        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, User::TEAM_MANAGEMENT_ROLES, true)
            ? Response::allow()
            : Response::deny();
    }
}
