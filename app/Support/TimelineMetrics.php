<?php

namespace App\Support;

use App\Models\CommEvent;
use App\Models\GameEvent;
use Illuminate\Support\Collection;

/**
 * The provisional timeline-summary numbers: communication frequency, event
 * counts by type, a redundant tally, and talk / silence figures built from
 * communication-event spans. Pure functions over a set of events and a window,
 * extracted from the controller so the boundaries can be unit-tested in
 * isolation. All of this is provisional pending the real Dead Air milestone
 * (see docs/adr/0006-communication-events.md).
 */
class TimelineMetrics
{
    /**
     * Communication events per minute over the session window, two decimals.
     */
    public static function frequencyPerMin(int $count, int $windowMs): float
    {
        if ($windowMs <= 0) {
            return 0.0;
        }

        return round($count / ($windowMs / 60000), 2);
    }

    /**
     * Counts by communication type plus the overall total. Redundancy is a
     * cross-cutting flag, not a type, so it is reported on its own via
     * redundantCount(), not mixed in here.
     *
     * @param  Collection<int, CommEvent>  $events
     * @return array{informative: int, declarative: int, compound: int, total: int}
     */
    public static function commEventCounts($events): array
    {
        return [
            'informative' => (int) $events->where('communication_type', CommEvent::TYPE_INFORMATIVE)->count(),
            'declarative' => (int) $events->where('communication_type', CommEvent::TYPE_DECLARATIVE)->count(),
            'compound' => (int) $events->where('communication_type', CommEvent::TYPE_COMPOUND)->count(),
            'total' => (int) $events->count(),
        ];
    }

    /**
     * Game-event counts by type plus the overall total, every type key always
     * present so the shape is stable when a session has none of a kind.
     *
     * @param  Collection<int, GameEvent>  $events
     * @return array{total: int, by_type: array{kill: int, death: int, spike_plant: int, spike_defuse: int, round_win: int, round_lost: int}}
     */
    public static function gameEventCounts($events): array
    {
        $tally = $events->countBy('type');

        $byType = [];

        foreach (GameEvent::TYPES as $type) {
            $byType[$type] = (int) $tally->get($type, 0);
        }

        return [
            'total' => (int) $events->count(),
            'by_type' => $byType,
        ];
    }

    /**
     * How many of the events are flagged redundant, across every type.
     *
     * @param  Collection<int, CommEvent>  $events
     */
    public static function redundantCount($events): int
    {
        return $events->where('is_redundant', true)->count();
    }

    /**
     * A part of the session window as a percentage of it, two decimals. Guarded
     * so a zero or unknown window yields 0.0 rather than dividing by zero.
     *
     * Formula: percentage = part_ms / window_ms * 100
     */
    public static function percentageOfWindow(int $partMs, int $windowMs): float
    {
        if ($windowMs <= 0) {
            return 0.0;
        }

        return round($partMs / $windowMs * 100, 2);
    }

    /**
     * Team-aggregate talk / silence proxy from communication-event spans, in
     * milliseconds: any player talking counts as not silent. Returns
     * [total_talk_ms, total_silence_ms, longest_silence_ms]. The summary
     * endpoint reports these as percentages of the window via
     * percentageOfWindow(); this stays in ms as the raw computation.
     *
     * @param  Collection<int, CommEvent>  $events
     * @return array{0: int, 1: int, 2: int}
     */
    public static function silenceProxy($events, int $windowMs): array
    {
        if ($windowMs <= 0) {
            return [0, 0, 0];
        }

        $intervals = $events
            ->map(fn ($event) => [
                max(0, (int) $event->start_ms),
                min($windowMs, (int) $event->end_ms),
            ])
            ->filter(fn (array $span) => $span[1] > $span[0])
            ->sortBy(0)
            ->values();

        $merged = [];
        foreach ($intervals as [$start, $end]) {
            if ($merged !== [] && $start <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        $totalTalk = array_sum(array_map(fn (array $span) => $span[1] - $span[0], $merged));

        $cursor = 0;
        $longestSilence = 0;
        foreach ($merged as [$start, $end]) {
            $longestSilence = max($longestSilence, $start - $cursor);
            $cursor = max($cursor, $end);
        }
        $longestSilence = max($longestSilence, $windowMs - $cursor);

        return [$totalTalk, $windowMs - $totalTalk, $longestSilence];
    }
}
