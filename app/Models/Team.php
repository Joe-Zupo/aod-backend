<?php

namespace App\Models;

use App\Events\SessionParticipantLeft;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_name',
        'team_code',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'disbanded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team): void {
            $team->team_code ??= static::generateTeamCode();
        });

        static::created(fn (Team $team) => $team->ensureSettings());
    }

    public function settings(): HasMany
    {
        return $this->hasMany(TeamSettings::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }

    /**
     * Guarantee this team's three named settings rows exist (dead-air threshold,
     * informative keywords, declarative keywords), seeding a keyword-bucket row's
     * default keywords the moment it's created. Self-healing: safe to call for a
     * team that already has some or all of its rows.
     *
     * @return Collection<string, TeamSettings> keyed by setting_name
     */
    public function ensureSettings(): Collection
    {
        $definitions = [
            TeamSettings::SETTING_DEAD_AIR_THRESHOLD => ['default' => ['setting_parameter' => 5000]],
            TeamSettings::SETTING_INFORMATIVE_KEYWORDS => ['default' => [], 'seed' => TeamKeyword::CATEGORY_INFORMATIVE],
            TeamSettings::SETTING_DECLARATIVE_KEYWORDS => ['default' => [], 'seed' => TeamKeyword::CATEGORY_DECLARATIVE],
        ];

        return collect($definitions)->map(function (array $definition, string $name) {
            $setting = $this->settings()->firstOrCreate(['setting_name' => $name], $definition['default']);
            $setting->setRelation('team', $this);

            if ($setting->wasRecentlyCreated && isset($definition['seed'])) {
                $words = $definition['seed'] === TeamKeyword::CATEGORY_INFORMATIVE
                    ? TeamKeyword::DEFAULT_INFORMATIVE_KEYWORDS
                    : TeamKeyword::DEFAULT_DECLARATIVE_KEYWORDS;

                $setting->seedKeywords($definition['seed'], $words);
            }

            return $setting->load('keywords');
        });
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['member_role', 'status', 'decided_by', 'decided_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'active');
    }

    public function pendingMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'pending');
    }

    /**
     * Update a member's pivot row and clean up their active session
     * participations together, as one seam. Every path by which a member's
     * active membership on this team ends — removal or self-leave; logout
     * doesn't touch this pivot, so it calls the cleanup directly — goes
     * through this method, so a future departure path can't add the pivot
     * update without the cleanup.
     */
    public function departMember(User $member, array $pivotAttributes): void
    {
        DB::transaction(function () use ($member, $pivotAttributes): void {
            $this->members()->updateExistingPivot($member->id, $pivotAttributes);
            $member->leaveActiveSessionParticipations();
        });
    }

    /**
     * Disband the team: return every active member to a teamless state,
     * leave their active session participations, and cancel whatever
     * non-terminal session the team has — in a fixed number of queries
     * regardless of roster size, rather than once per member.
     */
    public function disband(): void
    {
        DB::transaction(function (): void {
            $memberIds = $this->activeMembers()->pluck('users.id');

            $this->members()->newPivotStatement()
                ->where('team_id', $this->id)
                ->whereIn('user_id', $memberIds)
                ->update(['status' => 'removed', 'left_at' => now(), 'updated_at' => now()]);

            $departingParticipants = SessionParticipant::whereIn('user_id', $memberIds)
                ->whereNull('left_at')
                ->whereHas('session', fn ($query) => $query->where('team_id', $this->id)->nonTerminal())
                ->with('user')
                ->get();

            $leftAt = now();

            SessionParticipant::whereIn('id', $departingParticipants->pluck('id'))
                ->update(['left_at' => $leftAt]);

            $departingParticipants->each(function (SessionParticipant $participant) use ($leftAt): void {
                $participant->left_at = $leftAt;

                event(new SessionParticipantLeft($participant));
            });

            $this->sessions()->nonTerminal()->update(['status' => Session::STATUS_CANCELLED]);

            $this->disbanded_at = now();
            $this->save();
        });
    }

    /**
     * Generate a unique, shareable team code.
     */
    public static function generateTeamCode(): string
    {
        do {
            $code = 'TM-'.Str::upper(Str::random(8));
        } while (static::where('team_code', $code)->exists());

        return $code;
    }
}
