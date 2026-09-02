<?php

namespace App\Support;

use App\Models\CommEvent;
use App\Models\TeamKeyword;

/**
 * Pure clustering of Team Keyword hits into Communication Events. Kept free of
 * Eloquent so the gap, type and redundancy rules can be unit-tested in
 * isolation (see docs/adr/0006-communication-events.md).
 *
 * A hit is an associative array carrying at least `category` (informative /
 * declarative), `normalized_keyword`, `start_ms` and `end_ms`. Hits are passed
 * through untouched on each returned cluster's `hits` key.
 */
class CommEventClusterer
{
    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array<int, array{start_ms: int, end_ms: int, communication_type: string, is_redundant: bool, hits: array<int, array<string, mixed>>}>
     */
    public static function cluster(array $hits, int $paddingMs): array
    {
        if ($hits === []) {
            return [];
        }

        usort($hits, fn (array $a, array $b) => $a['start_ms'] <=> $b['start_ms']);

        $clusters = [];
        $current = [$hits[0]];
        // Running max of ends seen in the current cluster, not just the previous
        // hit's end: ASR word spans can be out of end-order, and the gap that
        // matters is to the last point anyone was still talking.
        $clusterEnd = (int) $hits[0]['end_ms'];

        foreach (array_slice($hits, 1) as $hit) {
            $gap = (int) $hit['start_ms'] - $clusterEnd;

            if ($gap > $paddingMs) {
                $clusters[] = self::summarise($current);
                $current = [];
                $clusterEnd = (int) $hit['end_ms'];
            }

            $current[] = $hit;
            $clusterEnd = max($clusterEnd, (int) $hit['end_ms']);
        }

        $clusters[] = self::summarise($current);

        return $clusters;
    }

    /**
     * @param  array<int, array<string, mixed>>  $hits
     * @return array{start_ms: int, end_ms: int, communication_type: string, is_redundant: bool, hits: array<int, array<string, mixed>>}
     */
    private static function summarise(array $hits): array
    {
        $categories = array_column($hits, 'category');
        $hasInformative = in_array(TeamKeyword::CATEGORY_INFORMATIVE, $categories, true);
        $hasDeclarative = in_array(TeamKeyword::CATEGORY_DECLARATIVE, $categories, true);

        $type = match (true) {
            $hasInformative && $hasDeclarative => CommEvent::TYPE_COMPOUND,
            $hasDeclarative => CommEvent::TYPE_DECLARATIVE,
            default => CommEvent::TYPE_INFORMATIVE,
        };

        return [
            // Span is the earliest hit start to the latest hit end. Max, not the
            // last hit's end: a short trailing hit must not truncate the span
            // (which would drop words from the event's content text).
            'start_ms' => (int) min(array_column($hits, 'start_ms')),
            'end_ms' => (int) max(array_column($hits, 'end_ms')),
            'communication_type' => $type,
            'is_redundant' => self::isRedundant($hits, $categories),
            'hits' => $hits,
        ];
    }

    /**
     * Redundant when the cluster repeats a Category (2+ hits of the same one)
     * or repeats a normalized keyword. Within-player only.
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @param  array<int, string>  $categories
     */
    private static function isRedundant(array $hits, array $categories): bool
    {
        foreach (array_count_values($categories) as $count) {
            if ($count >= 2) {
                return true;
            }
        }

        $keywords = array_column($hits, 'normalized_keyword');

        foreach (array_count_values($keywords) as $count) {
            if ($count >= 2) {
                return true;
            }
        }

        return false;
    }
}
