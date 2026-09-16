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
     * Reading the analysed timeline. While a session is `timeline_ready` the
     * timeline is coach-only, under review; a player only reaches it once the
     * session is `analysis_ready` (see docs/adr/0010-timeline-management.md).
     */
    public function viewTimeline(User $user, Session $session): Response
    {
        $membership = $this->isActiveMember($user, $session->team);

        if (! $membership->allowed()) {
            return $membership;
        }

        if (in_array($session->status, Session::TIMELINE_VISIBLE_STATUSES, true)) {
            return Response::allow();
        }

        return $this->isActiveCoach($user, $session->team);
    }

    /**
     * Every timeline-management action: the status transitions, marking
     * timestamps reviewed, creating / editing / deleting timestamps, and
     * authoring annotations. Any active Coach, main or assistant.
     */
    public function manageTimeline(User $user, Session $session): Response
    {
        $coach = $this->isActiveCoach($user, $session->team);

        if (! $coach->allowed()) {
            return $coach;
        }

        return in_array($session->status, [Session::STATUS_TIMELINE_READY, Session::STATUS_ANALYSIS_READY], true)
            ? Response::allow()
            : Response::deny('This session is not open for timeline management.');
    }

    /**
     * Authoring an annotation on the timeline: a `note` on a timestamp or a
     * `reply` on an existing annotation. A coach may while the session is under
     * review or analysis-ready; a player only once it is analysis-ready (see
     * docs/adr/0010-timeline-management.md).
     */
    public function annotateTimeline(User $user, Session $session): Response
    {
        $member = $this->isActiveMember($user, $session->team);

        if (! $member->allowed()) {
            return $member;
        }

        $coach = $this->isActiveCoach($user, $session->team)->allowed()
            && in_array($session->status, [Session::STATUS_TIMELINE_READY, Session::STATUS_ANALYSIS_READY], true);

        if ($coach || $session->status === Session::STATUS_ANALYSIS_READY) {
            return Response::allow();
        }

        return Response::deny('You cannot annotate this timeline until it is analysis-ready.');
    }

    /**
     * Any active team member may join a session that is still queuing or is
     * already under way. A mid-run joiner is harmless by construction: they
     * arrive at `needs_consent`, and `uploadRecording()` gates on the
     * participant's own `recording` status, so they can deliver nothing until
     * they consent and start recording themselves (see
     * docs/adr/0012-per-player-recording-uploads.md,
     * docs/adr/0013-recording-control-and-departure.md). Nobody joins a session
     * past recording, where there is no run to take part in.
     *
     * The single source of truth for that rule, so any future caller of
     * authorize('join', $session) gets the right answer without needing its own
     * status check.
     */
    public function join(User $user, Session $session): Response
    {
        $membership = $this->isActiveMember($user, $session->team);

        if (! $membership->allowed()) {
            return $membership;
        }

        return in_array($session->status, Session::NON_TERMINAL_STATUSES, true)
            ? Response::allow()
            : Response::deny('This session is not open for joining.');
    }

    /**
     * Any active team member may reach the consent endpoint; whether they
     * actually have anything to consent to (a current non-Coach participant
     * row, an open session) is the model's guard, surfaced as a 422 rather
     * than a policy denial.
     */
    public function consent(User $user, Session $session): Response
    {
        return $this->isActiveMember($user, $session->team);
    }

    /**
     * Any active team member may reach the upload endpoint; whether their own
     * participant row is actually eligible right now (status `recording`, not
     * left) is the model's guard, surfaced as a 422 rather than a policy
     * denial — the same split `consent()` uses.
     */
    public function uploadRecording(User $user, Session $session): Response
    {
        return $this->isActiveMember($user, $session->team);
    }

    /**
     * Any active team member may reach the leave endpoint. Whether they have
     * anything to leave — a participant row on this session, and one that is
     * still active — is the model's guard, surfaced as a 422 rather than a
     * policy denial, because a policy can only allow or deny and departure has
     * a third answer: already gone, which is a success.
     */
    public function leave(User $user, Session $session): Response
    {
        return $this->isActiveMember($user, $session->team);
    }

    /**
     * Any active team member may reach the recording endpoints. Whether they
     * have anything to start or stop — a player's participant row, on a session
     * that is actually recording — is the model's guard, surfaced as a 422, the
     * same split `consent` uses.
     */
    public function record(User $user, Session $session): Response
    {
        return $this->isActiveMember($user, $session->team);
    }

    /**
     * Any active Coach on the session's team may start or complete it, not just
     * whoever created it.
     */
    public function start(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }

    public function complete(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }

    /**
     * A coach-driven state move with no payload: cancel, re-analyze, mark
     * analysis-ready, reopen review. Any active Coach; whether the move is legal
     * from the session's current status is the model's guard, surfaced as 422
     * (see docs/adr/0010-timeline-management.md).
     */
    public function transition(User $user, Session $session): Response
    {
        return $this->isActiveCoach($user, $session->team);
    }
}
