<?php

namespace App\Support;

/**
 * The pure dead-air computation for one session: given every team member's
 * communication-event spans and the session window, return the team-aggregate
 * silent stretches strictly longer than the threshold as `{start_ms, end_ms}`
 * in start order (see docs/adr/0009-dead-air-detection.md).
 *
 * Any player talking breaks silence, so the spans are unioned first
 * (App\Support\Intervals::merge). Gaps are measured between the merged spans and
 * between the window edges (0 and $windowMs) and the spans. The threshold is a
 * minimum-gap test only, never a merge distance, so a period never contains a
 * callout. No Eloquent, tested in isolation like CommEventClusterer.
 */
class DeadAirDetector
{
    /**
     * @param  list<array{0: int, 1: int}>  $spans  communication-event [start_ms, end_ms] pairs, any order
     * @return list<array{start_ms: int, end_ms: int}>
     */
    public static function periods(array $spans, int $windowMs, int $thresholdMs): array
    {
        if ($windowMs <= 0) {
            return [];
        }

        $clamped = [];
        foreach ($spans as [$start, $end]) {
            $start = max(0, (int) $start);
            $end = min($windowMs, (int) $end);

            if ($end > $start) {
                $clamped[] = [$start, $end];
            }
        }

        $merged = Intervals::merge($clamped);

        $periods = [];
        $cursor = 0;

        foreach ($merged as [$start, $end]) {
            if ($start - $cursor > $thresholdMs) {
                $periods[] = ['start_ms' => $cursor, 'end_ms' => $start];
            }

            $cursor = $end;
        }

        if ($windowMs - $cursor > $thresholdMs) {
            $periods[] = ['start_ms' => $cursor, 'end_ms' => $windowMs];
        }

        return $periods;
    }

    /**
     * The `dead_air` annotation body for a period that has at least one uncalled
     * game event inside it: "<Ns> of team silence; <phrases> went uncalled.
     * Consider reviewing this moment." Each event is placed relative to the
     * period start ("1.1s in", "400 ms in"), capped at three then "and N more",
     * sharing GameEventNarrator with game-state alignment. The review nudge is
     * always present, because the annotation only exists when something happened
     * during the silence.
     *
     * @param  list<array{type: string, side: ?string, match_time_ms: int, note: ?string, raw: ?array<string, mixed>}>  $interiorEvents  in match_time_ms order
     */
    public static function body(int $periodStartMs, int $periodEndMs, array $interiorEvents): string
    {
        $duration = GameEventNarrator::seconds($periodEndMs - $periodStartMs);

        $phrases = array_map(function (array $event) use ($periodStartMs) {
            $offset = (int) $event['match_time_ms'] - $periodStartMs;

            return GameEventNarrator::clause($event).' '.GameEventNarrator::magnitude($offset).' in';
        }, $interiorEvents);

        return "{$duration}s of team silence; ".GameEventNarrator::list($phrases)
            .' went uncalled. '.GameEventNarrator::REVIEW_NUDGE;
    }
}
