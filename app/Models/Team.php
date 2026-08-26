<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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
