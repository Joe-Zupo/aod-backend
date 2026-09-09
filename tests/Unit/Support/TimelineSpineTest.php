<?php

namespace Tests\Unit\Support;

use App\Support\TimelineSpine;
use PHPUnit\Framework\TestCase;

/**
 * The shared timeline spine: game-event entries (a point in time) and dead-air
 * entries (an interval) merged into one list sorted by start_ms, ties putting a
 * game_event before a dead_air entry (see docs/adr/0009-dead-air-detection.md).
 */
class TimelineSpineTest extends TestCase
{
    public function test_entries_are_merged_and_sorted_by_start(): void
    {
        $merged = TimelineSpine::merge(
            [['type' => 'game_event', 'start_ms' => 30000], ['type' => 'game_event', 'start_ms' => 5000]],
            [['type' => 'dead_air', 'start_ms' => 12000], ['type' => 'dead_air', 'start_ms' => 40000]],
        );

        $this->assertSame([5000, 12000, 30000, 40000], array_column($merged, 'start_ms'));
    }

    public function test_a_game_event_sorts_before_a_dead_air_entry_at_the_same_start(): void
    {
        $merged = TimelineSpine::merge(
            [['type' => 'game_event', 'start_ms' => 10000, 'id' => 'g']],
            [['type' => 'dead_air', 'start_ms' => 10000, 'id' => 'd']],
        );

        $this->assertSame(['g', 'd'], array_column($merged, 'id'));
    }

    public function test_either_side_can_be_empty(): void
    {
        $this->assertSame(
            [['type' => 'dead_air', 'start_ms' => 0]],
            TimelineSpine::merge([], [['type' => 'dead_air', 'start_ms' => 0]]),
        );
        $this->assertSame([], TimelineSpine::merge([], []));
    }
}
