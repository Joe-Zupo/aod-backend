<?php

namespace App\Support;

/**
 * Pure span arithmetic. `merge` unions a set of `[start, end]` integer tuples
 * into a sorted, disjoint set, fusing spans that overlap or merely touch.
 * Kept free of Eloquent so TimelineMetrics::silenceProxy and DeadAirDetector
 * share one union rule (see docs/adr/0009-dead-air-detection.md).
 *
 * Each input span is assumed well-formed (`start <= end`); clamping and
 * dropping degenerate spans is the caller's job.
 */
class Intervals
{
    /**
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<array{0: int, 1: int}>
     */
    public static function merge(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($spans as [$start, $end]) {
            $last = count($merged) - 1;

            // Fuse when this span overlaps or merely touches the running one:
            // start <= last end, not <, so [0,100] and [100,200] become [0,200].
            if ($merged !== [] && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }
}
