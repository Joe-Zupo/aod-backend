<?php

namespace App\Support;

/**
 * Pure helpers for the team dashboard (issue #17), kept out of the
 * controller so the arithmetic can be unit-tested in isolation.
 */
class DashboardMetrics
{
    /**
     * The median of a list of numbers: the middle value for an odd count, the
     * mean of the two middle values for an even count. Null for an empty list.
     * Key order does not matter.
     *
     * @param  array<int|string, int|float>  $values
     */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        $sorted = array_values($values);
        sort($sorted);

        $count = count($sorted);
        $mid = intdiv($count, 2);

        if ($count % 2 === 1) {
            return (float) $sorted[$mid];
        }

        return ($sorted[$mid - 1] + $sorted[$mid]) / 2;
    }
}
