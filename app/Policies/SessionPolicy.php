<?php

namespace App\Policies;

use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamRole;
use Illuminate\Auth\Access\Response;

class SessionPolicy
{
    use ChecksTeamRole;

    /**
     * Browsing a team's session history is available to any active team
     * member, same tier as viewing a single session.
     */
    public function viewAny(User $user, Team $team): Response
    {
        return $this->isActiveMember($user, $team);
    }

    /**
     * Creating a session is coaching work: any active Coach, main or assistant,
     * may create one for the team named in the route.
     */
    public function create(User $user, Team $team): Response
    {
        return $this->isActiveCoach($user, $team);
    }

    public function view(User $user, Session $session): Response
    {
        return $this->isActiveMember($user, $session->team);
    }

    /**
     * Any active team member may join, but only while the session is still
     * queuing — this is the single source of truth for that rule, so any
     * future caller of authorize('join', $session) gets the right answer
     * without needing its own status check.
     */
    public function join(User $user, Session $session): Response
    {
        $membership = $this->isActiveMember($user, $session->team);

        if (! $membership->allowed()) {
            return $membership;
        }

        return $session->status === Session::STATUS_QUEUING
            ? Response::allow()
            : Response::deny('This session is not open for joining.');
    }

    /**
     * Any active Coach on the session's team may start/stop/cancel it, not just
     * whoever created it.
     */
    public function start(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }

    public function stop(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }

    public function cancel(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }
}
