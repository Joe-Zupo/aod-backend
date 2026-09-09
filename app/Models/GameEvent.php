<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One occurrence in the played game placed on the session timeline by
 * `match_time_ms`. Supplied as a hand-authored `game_events` payload on the
 * session completion call until Riot API access exists (see
 * docs/adr/0007-manual-game-event-ingest.md).
 */
class GameEvent extends Model
{
    use HasFactory, Reviewable;

    /**
     * Every game-event type, the single source of truth for the vocabulary
     * (see docs/adr/0007-manual-game-event-ingest.md).
     */
    public const TYPES = ['kill', 'death', 'spike_plant', 'spike_defuse', 'round_win', 'round_lost'];

    /**
     * Round-outcome types. They carry no `side` and their valence is fixed.
     */
    public const ROUND_TYPES = ['round_win', 'round_lost'];

    protected $fillable = [
        'session_id',
        'source',
        'type',
        'side',
        'match_time_ms',
        'round_number',
        'note',
        'raw',
        'reviewed_at',
        'reviewed_by',
        'created_by',
    ];

    protected $casts = [
        'match_time_ms' => 'integer',
        'round_number' => 'integer',
        'raw' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * Coach notes and their replies. The system never annotates a game event
     * directly (its findings live on communication events and dead-air
     * periods); this relation exists for hand-authored notes only (ADR 0010).
     */
    public function annotations(): MorphMany
    {
        return $this->morphMany(Annotation::class, 'annotatable');
    }
}
