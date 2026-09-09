<?php

namespace App\Support;

/**
 * The session timeline's shared chronological spine: game-event entries (a point
 * in time, `type: "game_event"`) and dead-air entries (an interval,
 * `type: "dead_air"`) merged into one list ordered by `start_ms` ascending,
 * ties putting a game_event before a dead_air entry (see
 * docs/adr/0009-dead-air-detection.md). Each entry is already a resource-shaped
 * array carrying at least `type` and `start_ms`.
 */
class TimelineSpine
{
    /**
     * @param  list<array<string, mixed>>  $gameEvents
     * @param  list<array<string, mixed>>  $deadAirPeriods
     * @return list<array<string, mixed>>
     */
    public static function merge(array $gameEvents, array $deadAirPeriods): array
    {
        $entries = array_merge(array_values($gameEvents), array_values($deadAirPeriods));

        usort($entries, fn (array $a, array $b) => [$a['start_ms'], self::rank($a['type'])]
            <=> [$b['start_ms'], self::rank($b['type'])]);

        return $entries;
    }

    private static function rank(string $type): int
    {
        return $type === 'game_event' ? 0 : 1;
    }
}
