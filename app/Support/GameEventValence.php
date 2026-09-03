<?php

namespace App\Support;

/**
 * How a game event favours our team, derived purely from its type and side
 * (see docs/adr/0007-manual-game-event-ingest.md). The payload never carries
 * a valence; it is read here so the rule stays in one place.
 */
class GameEventValence
{
    public const FAVOURABLE = 'favourable';

    public const UNFAVOURABLE = 'unfavourable';

    public const NEUTRAL = 'neutral';

    /**
     * Types whose valence is the plain reading of `side`. `death` is not here:
     * a `death` favours us only when `side` is `ally`, handled below.
     */
    private const SIDE_SCORED_TYPES = ['kill', 'spike_plant', 'spike_defuse'];

    public static function for(string $type, ?string $side): string
    {
        if ($type === 'round_win') {
            return self::FAVOURABLE;
        }

        if ($type === 'round_lost') {
            return self::UNFAVOURABLE;
        }

        if (in_array($type, self::SIDE_SCORED_TYPES, true)) {
            return $side === 'ally' ? self::FAVOURABLE : self::UNFAVOURABLE;
        }

        if ($type === 'death' && $side === 'ally') {
            return self::UNFAVOURABLE;
        }

        return self::NEUTRAL;
    }
}
