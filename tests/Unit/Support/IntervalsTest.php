<?php

namespace Tests\Unit\Support;

use App\Support\Intervals;
use PHPUnit\Framework\TestCase;

/**
 * The pure span union: overlapping and adjacent [start, end] tuples fused into
 * a sorted, disjoint set. Shared by TimelineMetrics::silenceProxy and
 * DeadAirDetector (see docs/adr/0009-dead-air-detection.md).
 */
class IntervalsTest extends TestCase
{
    public function test_no_spans_merge_to_nothing(): void
    {
        $this->assertSame([], Intervals::merge([]));
    }

    public function test_a_single_span_is_returned_as_is(): void
    {
        $this->assertSame([[100, 500]], Intervals::merge([[100, 500]]));
    }

    public function test_disjoint_spans_come_back_sorted_by_start(): void
    {
        $this->assertSame(
            [[0, 100], [200, 300], [500, 600]],
            Intervals::merge([[500, 600], [0, 100], [200, 300]]),
        );
    }

    public function test_overlapping_spans_fuse_to_their_union(): void
    {
        $this->assertSame(
            [[0, 700]],
            Intervals::merge([[0, 400], [300, 700]]),
        );
    }

    public function test_exactly_adjacent_spans_fuse(): void
    {
        $this->assertSame(
            [[0, 200]],
            Intervals::merge([[0, 100], [100, 200]]),
        );
    }

    public function test_a_contained_span_is_absorbed_by_its_container(): void
    {
        $this->assertSame(
            [[0, 1000]],
            Intervals::merge([[0, 1000], [200, 300]]),
        );
    }

    public function test_a_later_span_can_bridge_two_earlier_disjoint_ones(): void
    {
        $this->assertSame(
            [[0, 900]],
            Intervals::merge([[0, 100], [800, 900], [50, 850]]),
        );
    }
}
