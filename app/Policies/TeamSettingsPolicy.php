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

    /**
     * Managing the detection keyword list is the same coaching-tier work as
     * updating the dead-air threshold; kept as its own ability so the two can
     * diverge later without a migration of intent.
     */
    public function updateKeywords(User $user, TeamSettings $settings): Response
    {
        return $this->isActiveCoach($user, $settings->team);
    }
}
