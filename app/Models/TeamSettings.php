<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TeamSettings extends Model
{
    use HasFactory;

    public const SETTING_DEAD_AIR_THRESHOLD = 'dead_air_threshold';

    public const SETTING_INFORMATIVE_KEYWORDS = 'informative_keywords';

    public const SETTING_DECLARATIVE_KEYWORDS = 'declarative_keywords';

    public const SETTING_COMM_EVENT_PADDING = 'comm_event_padding_ms';

    protected $fillable = [
        'team_id',
        'setting_name',
        'setting_parameter',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * An unsaved instance carrying just enough state (the parent team) to
     * authorize against, without requiring a real settings row to exist or
     * persisting anything.
     */
    public static function unsavedFor(Team $team): self
    {
        $settings = new self(['team_id' => $team->id]);
        $settings->setRelation('team', $team);

        return $settings;
    }

    public function keywords(): HasMany
    {
        return $this->hasMany(TeamKeyword::class);
    }

    /**
     * Populate this setting row's keywords in one bulk insert. Only meaningful
     * for the informative/declarative keyword-bucket settings.
     *
     * @param  string[]  $keywords
     */
    public function seedKeywords(string $category, array $keywords): void
    {
        $now = now();

        $rows = collect($keywords)
            ->map(fn (string $keyword) => [
                'team_settings_id' => $this->id,
                'keyword' => $keyword,
                'category' => $category,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        TeamKeyword::insert($rows);
    }
}
