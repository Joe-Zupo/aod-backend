<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register
     *
     * Register an account, assign its role, and create or join a team when required.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        [$user, $team, $membershipStatus] = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'riot_id' => $validated['riot_id'] ?? null,
                'user_code' => User::generateUserCode($validated['role']),
            ]);

            $user->assignRole($validated['role']);

            $team = null;
            $membershipStatus = null;

            if (($validated['team_action'] ?? null) === 'create') {
                $team = Team::create([
                    'team_name' => $validated['team_name'],
                    'description' => $validated['description'] ?? null,
                ]);

                $team->members()->attach($user->id, [
                    'member_role' => 'main_coach',
                    'status' => 'active',
                    'joined_at' => now(),
                    'decided_by' => $user->id,
                    'decided_at' => now(),
                ]);
                $membershipStatus = 'active';
            } elseif (isset($validated['team_code'])) {
                $team = Team::where('team_code', Str::upper($validated['team_code']))
                    ->whereNull('disbanded_at')
                    ->first();

                if (! $team) {
                    throw ValidationException::withMessages([
                        'team_code' => ['The provided team code does not exist.'],
                    ]);
                }

                $team->members()->attach($user->id, [
                    'member_role' => $validated['role'] === 'Player' ? 'player' : 'assistant_coach',
                    'status' => 'pending',
                ]);
                $membershipStatus = 'pending';
            }

            return [$user, $team, $membershipStatus];
        });

        $user->load(['roles', 'activeTeams']);

        return $this->success('Registration successful.', [
            'user' => new UserResource($user),
            'team' => $team ? new TeamResource($team) : null,
            'team_membership_status' => $membershipStatus,
            'token' => $user->createToken('auth-token')->plainTextToken,
        ], 201);
    }

    /**
     * Login
     *
     * Authenticate a user with email and password, then issue an API token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $user->load(['roles', 'activeTeams']);

        return $this->success('Login successful.', [
            'user' => new UserResource($user),
            'token' => $user->createToken('auth-token')->plainTextToken,
        ]);
    }

    /**
     * Logout
     *
     * Revoke the API token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Logout successful.');
    }

    /**
     * User Return
     *
     * Return the authenticated user's profile, role, and team memberships.
     */
    public function user(Request $request): JsonResponse
    {
        return $this->success('Authenticated user retrieved.', [
            'user' => new UserResource($request->user()->load(['roles', 'activeTeams'])),
        ]);
    }
}
