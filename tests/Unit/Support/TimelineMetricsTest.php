<?php

namespace Tests\Unit\Support;

use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Support\TimelineMetrics;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class TimelineMetricsTest extends TestCase
{
    /**
     * @param  array<int, array{start: int, end: int, type?: string, redundant?: bool}>  $events
     * @return Collection<int, CommEvent>
     */
    private function events(array $events): Collection
    {
        return collect($events)->map(fn (array $e) => new CommEvent([
            'start_ms' => $e['start'],
            'end_ms' => $e['end'],
            'communication_type' => $e['type'] ?? CommEvent::TYPE_INFORMATIVE,
            'is_redundant' => $e['redundant'] ?? false,
        ]));
    }

    public function test_frequency_per_min_is_zero_for_a_non_positive_window(): void
    {
        $this->assertSame(0.0, TimelineMetrics::frequencyPerMin(5, 0));
        $this->assertSame(0.0, TimelineMetrics::frequencyPerMin(5, -1));
    }

    public function test_frequency_per_min_scales_the_count_to_a_minute(): void
    {
        $this->assertSame(4.0, TimelineMetrics::frequencyPerMin(4, 60000));
        $this->assertSame(2.0, TimelineMetrics::frequencyPerMin(1, 30000));
    }

    public function test_counts_tally_each_type_plus_total_without_redundant(): void
    {
        $counts = TimelineMetrics::commEventCounts($this->events([
            ['start' => 0, 'end' => 1, 'redundant' => true],
            ['start' => 2, 'end' => 3],
            ['start' => 4, 'end' => 5, 'type' => CommEvent::TYPE_DECLARATIVE],
            ['start' => 6, 'end' => 7, 'type' => CommEvent::TYPE_COMPOUND],
        ]));

        $this->assertSame(
            ['informative' => 2, 'declarative' => 1, 'compound' => 1, 'total' => 4],
            $counts,
        );
    }

    public function test_game_event_counts_tally_each_type_plus_total(): void
    {
        $counts = TimelineMetrics::gameEventCounts(collect([
            'kill', 'kill', 'death', 'spike_plant', 'round_win', 'round_win', 'round_lost',
        ])->map(fn (string $type) => new GameEvent(['type' => $type])));

        $this->assertSame([
            'total' => 7,
            'by_type' => [
                'kill' => 2,
                'death' => 1,
                'spike_plant' => 1,
                'spike_defuse' => 0,
                'round_win' => 2,
                'round_lost' => 1,
            ],
        ], $counts);
    }

    public function test_game_event_counts_are_all_zero_for_no_events(): void
    {
        $this->assertSame([
            'total' => 0,
            'by_type' => [
                'kill' => 0,
                'death' => 0,
                'spike_plant' => 0,
                'spike_defuse' => 0,
                'round_win' => 0,
                'round_lost' => 0,
            ],
        ], TimelineMetrics::gameEventCounts(collect()));
    }

    public function test_redundant_count_tallies_the_flag_across_every_type(): void
    {
        $count = TimelineMetrics::redundantCount($this->events([
            ['start' => 0, 'end' => 1, 'redundant' => true],
            ['start' => 2, 'end' => 3, 'type' => CommEvent::TYPE_DECLARATIVE, 'redundant' => true],
            ['start' => 4, 'end' => 5, 'type' => CommEvent::TYPE_COMPOUND],
        ]));

        $this->assertSame(2, $count);
    }

    public function test_percentage_of_window_is_zero_for_a_non_positive_window(): void
    {
        $this->assertSame(0.0, TimelineMetrics::percentageOfWindow(5000, 0));
    }

    public function test_percentage_of_window_is_the_part_over_the_window_times_100(): void
    {
        $this->assertSame(35.0, TimelineMetrics::percentageOfWindow(21000, 60000));
        $this->assertSame(41.67, TimelineMetrics::percentageOfWindow(25000, 60000));
    }

    public function test_silence_proxy_is_all_zero_for_a_non_positive_window(): void
    {
        $this->assertSame([0, 0, 0], TimelineMetrics::silenceProxy($this->events([]), 0));
    }

    public function test_silence_proxy_merges_overlapping_spans_and_finds_the_longest_gap(): void
    {
        // Talk spans merge to [0,10000] + [20000,30000] + [55000,56000] = 21000ms.
        [$talk, $silence, $longest] = TimelineMetrics::silenceProxy($this->events([
            ['start' => 0, 'end' => 10000],
            ['start' => 20000, 'end' => 25000],
            ['start' => 24000, 'end' => 30000],
            ['start' => 55000, 'end' => 56000],
        ]), 60000);

        $this->assertSame(21000, $talk);
        $this->assertSame(39000, $silence);
        // Longest gap is 30000 -> 55000 = 25000ms.
        $this->assertSame(25000, $longest);
    }

    public function test_silence_proxy_clamps_spans_to_the_window(): void
    {
        [$talk, $silence, $longest] = TimelineMetrics::silenceProxy($this->events([
            ['start' => -5000, 'end' => 4000],
            ['start' => 8000, 'end' => 20000],
        ]), 10000);

        // Clamped to [0,4000] + [8000,10000] = 6000ms talk.
        $this->assertSame(6000, $talk);
        $this->assertSame(4000, $silence);
        // Longest gap is 4000 -> 8000 = 4000ms.
        $this->assertSame(4000, $longest);
    }
}
