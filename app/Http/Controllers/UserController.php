<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Profile Return
     *
     * Return the authenticated user's profile, roles, and active team memberships.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['roles', 'activeTeams']);

        return $this->success('Profile retrieved.', ['user' => new UserResource($user)]);
    }

    /**
     * Update Profile
     *
     * Update the authenticated user's permitted profile fields without allowing role changes.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('update', $user);
        $user->update($request->safe()->only(['username', 'email', 'riot_id']));

        return $this->success('Profile updated.', ['user' => new UserResource($user->load(['roles', 'activeTeams']))]);
    }

    /**
     * Update Password
     *
     * Change the authenticated user's password after verifying their current password.
     */
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->authorize('update', $user);
        $user->update(['password' => $request->validated('password')]);

        return $this->success('Password updated.');
    }

    /**
     * Active Teams Return
     *
     * Return only the teams in which the authenticated user is currently an active member.
     */
    public function teams(Request $request): JsonResponse
    {
        return $this->success('Active teams retrieved.', [
            'teams' => TeamResource::collection($request->user()->activeTeams()->get()),
        ]);
    }
}
