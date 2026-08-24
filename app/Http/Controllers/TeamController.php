<?php

namespace App\Http\Controllers;

use App\Http\Requests\DecideJoinRequest;
use App\Http\Requests\JoinTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Http\Resources\TeamMemberResource;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TeamController extends Controller
{
    /**
     * Request to Join
     *
     * Submit a join request for a team by its team code. The request stays pending
     * until the main coach of that team approves or rejects it. A user
     * may only have one active membership or pending request at a time.
     */
    public function join(JoinTeamRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->pendingOrActiveTeams()->exists()) {
            return $this->error('You already belong to a team or have a pending join request.', 422);
        }

        $team = Team::where('team_code', Str::upper($request->validated('team_code')))
            ->whereNull('disbanded_at')
            ->first();

        if (! $team) {
            throw ValidationException::withMessages([
                'team_code' => ['The provided team code does not exist.'],
            ]);
        }

        $role = $user->getRoleNames()->first();

        $team->members()->syncWithoutDetaching([
            $user->id => [
                'member_role' => $role === 'Player' ? 'player' : 'assistant_coach',
                'status' => 'pending',
                'joined_at' => null,
                'left_at' => null,
                'decided_by' => null,
                'decided_at' => null,
            ],
        ]);

        return $this->success('Join request submitted.', ['team' => new TeamResource($team)]);
    }

    /**
     * Join Requests Return
     *
     * Return the pending join requests for a team. Restricted to the team's main
     * coach.
     */
    public function joinRequests(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('manageMembers', $team);

        return $this->success('Pending join requests retrieved.', [
            'requests' => TeamMemberResource::collection(
                $team->pendingMembers()->orderBy('username')->get()
            ),
        ]);
    }

    /**
     * Decide Join Request
     *
     * Approve or reject a pending join request. Approving a player request is
     * rejected once the team already has 5 active players. Restricted to the
     * team's main coach.
     */
    public function decideJoinRequest(DecideJoinRequest $request, User $user): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('manageMembers', $team);

        $pending = $team->pendingMembers()->whereKey($user->id)->first();

        if (! $pending) {
            return $this->error('No pending join request found for this user.', 404);
        }

        if ($request->validated('action') === 'reject') {
            $team->members()->updateExistingPivot($user->id, [
                'status' => 'rejected',
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ]);

            return $this->success('Join request rejected.');
        }

        if ($pending->pivot->member_role === 'player'
            && $team->activeMembers()->wherePivot('member_role', 'player')->count() >= 5) {
            return $this->error('This team already has the maximum of 5 players.', 422);
        }

        $team->members()->updateExistingPivot($user->id, [
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        return $this->success('Join request approved.', ['team' => new TeamResource($team->fresh())]);
    }

    /**
     * Remove Member
     *
     * Remove an active player or assistant coach from the team, returning them to
     * a teamless state. The main coach cannot be removed this way; see leave().
     * Restricted to the team's main coach.
     */
    public function removeMember(Request $request, User $user): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('manageMembers', $team);

        $membership = $team->activeMembers()->whereKey($user->id)->first();

        if (! $membership || in_array($membership->pivot->member_role, User::TEAM_MANAGEMENT_ROLES, true)) {
            return $this->error('This member cannot be removed.', 422);
        }

        $team->members()->updateExistingPivot($user->id, [
            'status' => 'removed',
            'left_at' => now(),
            'decided_by' => $request->user()->id,
            'decided_at' => now(),
        ]);

        return $this->success('Member removed from the team.');
    }

    /**
     * Leave Team
     *
     * Leave the authenticated user's active team. If the leaving member is the
     * main coach, the entire team is disbanded: every active member,
     * including the leader, is returned to a teamless state.
     */
    public function leave(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $user->resolveTeam($request);
        $membership = $team->activeMembers()->whereKey($user->id)->first();

        if (! $membership) {
            return $this->error('You are not an active member of this team.', 422);
        }

        if (in_array($membership->pivot->member_role, User::TEAM_MANAGEMENT_ROLES, true)) {
            DB::transaction(function () use ($team): void {
                foreach ($team->activeMembers()->get() as $member) {
                    $team->members()->updateExistingPivot($member->id, [
                        'status' => 'removed',
                        'left_at' => now(),
                    ]);
                }

                $team->disbanded_at = now();
                $team->save();
            });

            return $this->success('You left the team. The team has been disbanded.');
        }

        $team->members()->updateExistingPivot($user->id, [
            'status' => 'removed',
            'left_at' => now(),
        ]);

        return $this->success('You left the team.');
    }

    /**
     * Team Return
     *
     * Return a team, including its active members, only when the authenticated
     * user is an active member of that team.
     */
    public function show(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('view', $team);

        return $this->success('Team retrieved.', [
            'team' => new TeamResource(
                $team->load(['activeMembers' => fn ($query) => $query->orderBy('username')])
            ),
        ]);
    }

    /**
     * Update Team
     *
     * Allow only the active main coach to update a team's name or description.
     * Team codes are generated at creation and cannot be changed.
     */
    public function update(UpdateTeamRequest $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('update', $team);
        $team->update($request->safe()->only(['team_name', 'description']));

        return $this->success('Team updated.', ['team' => new TeamResource($team->fresh())]);
    }
}
