<?php

namespace App\Models;

use App\Events\SessionParticipantLeft;
use App\Support\Broadcasting;
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
     * Guarantee this team's named settings rows exist (dead-air threshold,
     * informative keywords, declarative keywords, communication-event padding),
     * seeding a keyword-bucket row's default keywords the moment it's created.
     * Self-healing: safe to call for a team that already has some or all of its
     * rows.
     *
     * @return Collection<string, TeamSettings> keyed by setting_name
     */
    public function ensureSettings(): Collection
    {
        $definitions = [
            TeamSettings::SETTING_DEAD_AIR_THRESHOLD => ['default' => ['setting_parameter' => 5000]],
            TeamSettings::SETTING_INFORMATIVE_KEYWORDS => ['default' => [], 'seed' => TeamKeyword::CATEGORY_INFORMATIVE],
            TeamSettings::SETTING_DECLARATIVE_KEYWORDS => ['default' => [], 'seed' => TeamKeyword::CATEGORY_DECLARATIVE],
            TeamSettings::SETTING_COMM_EVENT_PADDING => ['default' => ['setting_parameter' => 2000]],
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

    /**
     * The team's configured keyword strings across both category buckets,
     * de-duplicated. Sent to AssemblyAI as a word-boost list at transcription
     * submit time, which is also what snapshots them for a session: a later
     * edit to Team Settings does not reach an already-submitted transcript.
     *
     * @return string[]
     */
    public function keywordList(): array
    {
        return TeamKeyword::query()
            ->whereIn('team_settings_id', $this->settings()->select('id'))
            ->pluck('keyword')
            ->unique()
            ->values()
            ->all();
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

            $leftAt = now();

            $departingIds = SessionParticipant::whereIn('user_id', $memberIds)
                ->whereNull('left_at')
                ->whereHas('session', fn ($query) => $query->where('team_id', $this->id)->nonTerminal())
                ->pluck('id');

            // Re-checks left_at IS NULL at write time, not just at the
            // SELECT above: a participant who left through a different,
            // concurrent path between the two queries keeps their real
            // timestamp instead of being silently overwritten by this one.
            SessionParticipant::whereIn('id', $departingIds)
                ->whereNull('left_at')
                ->update(['left_at' => $leftAt]);

            // Only the rows the guarded update above actually touched get a
            // departure broadcast here — a participant it skipped already
            // left (and was already broadcast) through that other path.
            SessionParticipant::whereIn('id', $departingIds)
                ->where('left_at', $leftAt)
                ->with('user')
                ->get()
                ->each(fn (SessionParticipant $participant) => Broadcasting::safely(new SessionParticipantLeft($participant)));

            // Same invariant every other departure goes through (leave()),
            // not a blind bulk cancel: a session is cancelled once it has no
            // active participant left, checked per session rather than
            // assumed true just because the whole roster is departing.
            $this->sessions()->nonTerminal()->get()->each->cancelIfNoParticipantsRemain();

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
