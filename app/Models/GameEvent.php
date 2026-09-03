<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occurrence in the played game placed on the session timeline by
 * `match_time_ms`. Supplied as a hand-authored `game_events` payload on the
 * session completion call until Riot API access exists (see
 * docs/adr/0007-manual-game-event-ingest.md).
 */
class GameEvent extends Model
{
    use HasFactory;

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
    ];

    protected $casts = [
        'match_time_ms' => 'integer',
        'round_number' => 'integer',
        'raw' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
