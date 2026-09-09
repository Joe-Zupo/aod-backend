<?php

namespace Tests\Unit\Support;

use App\Support\DeadAirDetector;
use PHPUnit\Framework\TestCase;

/**
 * The pure dead-air computation: team-aggregate communication spans and a
 * session window in, the silent stretches longer than the threshold out, as
 * `{start_ms, end_ms}` in start order. Includes the leading and trailing window
 * gaps. No Eloquent, tested in isolation like CommEventClusterer (see
 * docs/adr/0009-dead-air-detection.md).
 */
class DeadAirDetectorTest extends TestCase
{
    private const THRESHOLD = 5000;

    /**
     * @param  list<array{0: int, 1: int}>  $spans
     * @return list<array{start_ms: int, end_ms: int}>
     */
    private function periods(array $spans, int $windowMs, int $thresholdMs = self::THRESHOLD): array
    {
        return DeadAirDetector::periods($spans, $windowMs, $thresholdMs);
    }

    public function test_a_session_with_no_communication_is_one_period_over_the_whole_window(): void
    {
        $this->assertSame(
            [['start_ms' => 0, 'end_ms' => 120000]],
            $this->periods([], 120000),
        );
    }

    public function test_a_silent_window_no_longer_than_the_threshold_has_no_period(): void
    {
        $this->assertSame([], $this->periods([], 5000));
        $this->assertSame([], $this->periods([], 4000));
    }

    public function test_a_zero_or_negative_window_has_no_periods(): void
    {
        $this->assertSame([], $this->periods([[0, 1000]], 0));
        $this->assertSame([], $this->periods([], -1));
    }

    public function test_a_gap_between_two_callouts_longer_than_the_threshold_is_a_period(): void
    {
        // Talk 0-2000 and 15000-16000, window 20000. Gaps: 2000-15000 (13s, a
        // period), and 16000-20000 (4s, under threshold).
        $this->assertSame(
            [['start_ms' => 2000, 'end_ms' => 15000]],
            $this->periods([[0, 2000], [15000, 16000]], 20000),
        );
    }

    public function test_the_threshold_boundary_is_strict(): void
    {
        // Gap of exactly 5000: not a period.
        $this->assertSame([], $this->periods([[0, 1000], [6000, 7000]], 7000));

        // Gap of 5001: a period.
        $this->assertSame(
            [['start_ms' => 1000, 'end_ms' => 6001]],
            $this->periods([[0, 1000], [6001, 7000]], 7000),
        );
    }

    public function test_the_leading_gap_before_the_first_callout_counts(): void
    {
        $this->assertSame(
            [['start_ms' => 0, 'end_ms' => 8000]],
            $this->periods([[8000, 9000]], 10000),
        );
    }

    public function test_the_trailing_gap_after_the_last_callout_counts(): void
    {
        $this->assertSame(
            [['start_ms' => 1000, 'end_ms' => 30000]],
            $this->periods([[0, 1000]], 30000),
        );
    }

    public function test_overlapping_input_spans_are_merged_before_gaps_are_measured(): void
    {
        // Two overlapping talk spans 0-6000 and 5000-10000 are one span 0-10000,
        // so the only period is the trailing gap.
        $this->assertSame(
            [['start_ms' => 10000, 'end_ms' => 40000]],
            $this->periods([[0, 6000], [5000, 10000]], 40000),
        );
    }

    public function test_a_callout_running_past_the_window_does_not_extend_talk(): void
    {
        // Talk 0-1000 then 50000-999999; the second span clamps to the window
        // end (60000), so there is a leading period and no trailing one.
        $this->assertSame(
            [['start_ms' => 1000, 'end_ms' => 50000]],
            $this->periods([[0, 1000], [50000, 999999]], 60000),
        );
    }

    public function test_multiple_periods_come_back_in_start_order(): void
    {
        $this->assertSame(
            [
                ['start_ms' => 0, 'end_ms' => 10000],
                ['start_ms' => 11000, 'end_ms' => 25000],
                ['start_ms' => 26000, 'end_ms' => 60000],
            ],
            $this->periods([[10000, 11000], [25000, 26000]], 60000),
        );
    }

    public function test_body_names_the_uncalled_events_relative_to_the_period_start(): void
    {
        $body = DeadAirDetector::body(40000, 48500, [
            ['type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 41100, 'note' => null, 'raw' => null],
            ['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 43200, 'note' => 'Sova', 'raw' => null],
        ]);

        $this->assertSame(
            '8.5s of team silence; enemy spike_plant 1.1s in; Sova fragged 3.2s in went uncalled. Consider reviewing this moment.',
            $body,
        );
    }

    public function test_body_uses_ms_for_a_sub_second_offset_and_caps_at_three(): void
    {
        $body = DeadAirDetector::body(0, 20000, [
            ['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 400, 'note' => null, 'raw' => null],
            ['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 800, 'note' => null, 'raw' => null],
            ['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 1200, 'note' => null, 'raw' => null],
            ['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 1600, 'note' => null, 'raw' => null],
        ]);

        $this->assertStringStartsWith('20s of team silence; an enemy kill 400 ms in;', $body);
        $this->assertStringEndsWith('and 1 more went uncalled. Consider reviewing this moment.', $body);
    }
}
