<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A note on a timeline entry. System-generated when `user_id` is null
 * (game-state alignment on a communication event, ADR 0008; dead-air
 * correspondence on a dead-air period, ADR 0009). A coach or player authors a
 * `note`, and any annotation, system or human, can have one level of `reply`
 * rows hanging off it through `parent_id` (ADR 0010).
 */
class Annotation extends Model
{
    use HasFactory;

    /**
     * The soft valence of the game state around a communication event, set on
     * every `game_state_alignment` row (a narration row for a callout that maps
     * no kind still takes a sign from the nearest game event); every dead-air
     * row leaves it null.
     */
    public const TOPIC_GAME_STATE_ALIGNMENT = 'game_state_alignment';

    public const TOPIC_DEAD_AIR = 'dead_air';

    public const TOPIC_NOTE = 'note';

    public const TOPIC_REPLY = 'reply';

    /**
     * The topics a human authors. A `game_state_alignment` or `dead_air` row is
     * system-only and immutable through the API.
     */
    public const HUMAN_TOPICS = [self::TOPIC_NOTE, self::TOPIC_REPLY];

    public const ASSESSMENT_POSSIBLY_POSITIVE = 'possibly_positive';

    public const ASSESSMENT_POSSIBLY_NEGATIVE = 'possibly_negative';

    public const ASSESSMENT_NEUTRAL = 'neutral';

    protected $fillable = [
        'annotatable_type',
        'annotatable_id',
        'parent_id',
        'user_id',
        'topic',
        'assessment',
        'body',
        'game_event_ids',
        'alignment_window_ms',
    ];

    protected $casts = [
        'game_event_ids' => 'array',
        'alignment_window_ms' => 'integer',
    ];

    public function annotatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * The reply rows hanging off this annotation, oldest first. One level only:
     * a reply's own `replies` is always empty (enforced at write time).
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function isSystem(): bool
    {
        return $this->user_id === null;
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * The session this annotation's timeline entry belongs to, for authorizing
     * the annotation routes that carry no `{session}` in the path.
     */
    public function timelineSession(): ?Session
    {
        $target = $this->annotatable;

        return match (true) {
            $target instanceof CommEvent => $target->transcript?->session(),
            $target instanceof GameEvent, $target instanceof DeadAirPeriod => $target->session,
            default => null,
        };
    }
}
