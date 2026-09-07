<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A note on a timeline entry. System-generated when `user_id` is null, which is
 * every row today: game-state alignment on a communication event (ADR 0008) and
 * dead-air correspondence on a dead-air period (ADR 0009). Coach and player
 * free-text notes will share the table, attaching through the same polymorphic
 * `annotatable`.
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

    public const ASSESSMENT_POSSIBLY_POSITIVE = 'possibly_positive';

    public const ASSESSMENT_POSSIBLY_NEGATIVE = 'possibly_negative';

    public const ASSESSMENT_NEUTRAL = 'neutral';

    protected $fillable = [
        'annotatable_type',
        'annotatable_id',
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
}
