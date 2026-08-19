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

        [$user, $team] = DB::transaction(function () use ($validated): array {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'riot_id' => $validated['riot_id'] ?? null,
            ]);

            $user->assignRole($validated['role']);

            $team = null;
            if (($validated['team_action'] ?? null) === 'create') {
                $team = Team::create([
                    'team_code' => $this->generateTeamCode(),
                    'team_name' => $validated['team_name'],
                ]);
            }

            if (isset($validated['team_code'])) {
                $team = Team::where('team_code', Str::upper($validated['team_code']))->first();

                if (! $team) {
                    throw ValidationException::withMessages([
                        'team_code' => ['The provided team code does not exist.'],
                    ]);
                }
            }

            if ($team) {
                $team->members()->attach($user->id, [
                    'joined_at' => now(),
                    'is_active' => true,
                ]);
            }

            return [$user, $team];
        });

        $user->load(['roles', 'teams']);

        return $this->success('Registration successful.', [
            'user' => new UserResource($user),
            'team' => $team ? new TeamResource($team) : null,
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

        $user->load(['roles', 'teams']);

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
            'user' => new UserResource($request->user()->load(['roles', 'teams'])),
        ]);
    }

    /**
     * Create a unique, shareable code for a newly created team.
     */
    private function generateTeamCode(): string
    {
        do {
            $teamCode = Str::upper(Str::random(8));
        } while (Team::where('team_code', $teamCode)->exists());

        return $teamCode;
    }
}
