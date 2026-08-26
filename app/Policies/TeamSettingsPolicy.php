<?php

namespace App\Policies;

use App\Models\TeamSettings;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamRole;
use Illuminate\Auth\Access\Response;

class TeamSettingsPolicy
{
    use ChecksTeamRole;

    /**
     * Configuring detection settings is coaching work: any active Coach, main or
     * assistant, may view or update it. A user with no active membership is
     * denied as not found, matching TeamPolicy::view's outsider handling.
     */
    public function view(User $user, TeamSettings $settings): Response
    {
        return $this->isActiveCoach($user, $settings->team);
    }

    public function update(User $user, TeamSettings $settings): Response
    {
        return $this->isActiveCoach($user, $settings->team);
    }
}
