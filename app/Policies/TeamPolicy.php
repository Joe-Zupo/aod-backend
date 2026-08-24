<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TeamPolicy
{
    public function view(User $user, Team $team): Response
    {
        return $this->isActiveMember($user, $team)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function update(User $user, Team $team): bool
    {
        return $this->hasManagementRole($user, $team);
    }

    /**
     * Only the main coach can manage membership: view and decide join requests, and
     * remove members. Assistant coaches are excluded by design. member_role is the
     * only place this per-team distinction exists (spatie roles are global), so
     * this one check reads it directly rather than being spatie-driven.
     */
    public function manageMembers(User $user, Team $team): bool
    {
        return $this->hasManagementRole($user, $team);
    }

    private function isActiveMember(User $user, Team $team): bool
    {
        return $user->activeTeams()->whereKey($team->id)->exists();
    }

    private function hasManagementRole(User $user, Team $team): bool
    {
        return $user->activeTeams()
            ->whereKey($team->id)
            ->wherePivotIn('member_role', User::TEAM_MANAGEMENT_ROLES)
            ->exists();
    }
}
