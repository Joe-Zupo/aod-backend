<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Events\UserWentOffline;
use App\Events\UserWentOnline;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['username', 'email', 'password', 'riot_id', 'user_code'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const TEAM_MANAGEMENT_ROLES = ['main_coach'];

    public const TEAM_COACH_ROLES = ['main_coach', 'assistant_coach'];

    /**
     * Per-instance cache for teamRole(), keyed by team id — a single request
     * often checks a user's role via a policy and then reuses it (e.g.
     * snapshotting participant_role right after an authorize() call), so
     * this avoids re-querying the same pivot row twice.
     */
    private array $teamRoleCache = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $fillable = [
        'username',
        'email',
        'password',
        'riot_id',
        'user_code',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_online' => 'boolean',
        ];
    }

    public function sessionParticipations(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    /**
     * Leave every non-terminal session this user is still an active
     * participant in. Called wherever this user's active involvement with a
     * team can end — logout, being removed, leaving, or a team disbanding —
     * so a departed user never keeps a session artificially alive.
     */
    public function leaveActiveSessionParticipations(): void
    {
        $this->sessionParticipations()
            ->whereNull('left_at')
            ->whereHas('session', fn ($query) => $query->nonTerminal())
            ->with('session')
            ->get()
            ->each->leave();
    }

    /**
     * Mark the user online and broadcast it to their active team, if they
     * have one. Reflects "authenticated since last logout," not live
     * connection presence — a user who closes the app without logging out
     * stays flagged online until they explicitly log out again.
     */
    public function goOnline(): void
    {
        // forceFill, not update: is_online is deliberately not in $fillable
        // — it should only ever be set through this seam (or goOffline()),
        // never via mass assignment from request input elsewhere.
        $this->forceFill(['is_online' => true])->save();

        $team = $this->activeTeams()->first();

        if ($team) {
            event(new UserWentOnline($this, $team));
        }
    }

    /**
     * Mark the user offline and broadcast it to their active team, if they
     * have one.
     */
    public function goOffline(): void
    {
        $this->forceFill(['is_online' => false])->save();

        $team = $this->activeTeams()->first();

        if ($team) {
            event(new UserWentOffline($this, $team));
        }
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->withPivot(['member_role', 'status', 'decided_by', 'decided_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * Get the teams the user can currently access.
     */
    public function activeTeams(): BelongsToMany
    {
        return $this->teams()->wherePivot('status', 'active');
    }

    /**
     * Get the team the user currently has an active membership or pending request with, if any.
     */
    public function pendingOrActiveTeams(): BelongsToMany
    {
        return $this->teams()->wherePivotIn('status', ['pending', 'active']);
    }

    /**
     * The user's member_role on their active membership for the given team, or
     * null if they have no active membership there. Shared by policies that
     * gate on team-scoped roles.
     */
    public function teamRole(Team $team): ?string
    {
        return $this->teamRoleCache[$team->id] ??= $this->activeTeams()->whereKey($team->id)->first()?->pivot->member_role;
    }

    /**
     * Resolve the team a request should act on: the `?team=` query parameter if
     * given, otherwise the user's own current active team. Lets every team-scoped
     * route omit the team id entirely and default to "my team".
     */
    public function resolveTeam(Request $request): Team
    {
        $teamId = $request->query('team');

        $team = $teamId !== null
            ? Team::find($teamId)
            : $this->activeTeams()->first();

        abort_if(! $team, 404, 'Team not found.');

        return $team;
    }

    /**
     * Generate a unique user code prefixed according to the user's registration role.
     */
    public static function generateUserCode(string $role): string
    {
        $prefix = $role === 'Coach' ? 'CH' : 'PL';

        do {
            $code = $prefix.'-'.Str::upper(Str::random(8));
        } while (static::where('user_code', $code)->exists());

        return $code;
    }
}
