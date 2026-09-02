<?php

namespace Tests\Unit\Support;

use App\Models\CommEvent;
use App\Support\CommEventClusterer;
use PHPUnit\Framework\TestCase;

/**
 * The gap, type and redundancy rules of Communication Event clustering, in
 * isolation from Eloquent (see docs/adr/0006-communication-events.md).
 */
class CommEventClustererTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function hit(int $start, int $end, string $category = 'informative', string $keyword = 'here', int $wordId = 1): array
    {
        return [
            'transcript_word_id' => $wordId,
            'keyword' => $keyword,
            'normalized_keyword' => $keyword,
            'category' => $category,
            'start_ms' => $start,
            'end_ms' => $end,
            'confidence' => 0.9,
        ];
    }

    public function test_no_hits_yields_no_clusters(): void
    {
        $this->assertSame([], CommEventClusterer::cluster([], 2000));
    }

    public function test_a_lone_hit_is_a_cluster_of_one(): void
    {
        $clusters = CommEventClusterer::cluster([$this->hit(1000, 1200, 'declarative', 'planting')], 2000);

        $this->assertCount(1, $clusters);
        $this->assertSame(1000, $clusters[0]['start_ms']);
        $this->assertSame(1200, $clusters[0]['end_ms']);
        $this->assertSame(CommEvent::TYPE_DECLARATIVE, $clusters[0]['communication_type']);
        $this->assertFalse($clusters[0]['is_redundant']);
        $this->assertCount(1, $clusters[0]['hits']);
    }

    public function test_a_gap_equal_to_the_padding_keeps_hits_in_one_cluster(): void
    {
        // end 1200, next start 3200 -> gap 2000 == padding, stays together.
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(3200, 3400, 'declarative', 'planting'),
        ], 2000);

        $this->assertCount(1, $clusters);
        $this->assertSame(1000, $clusters[0]['start_ms']);
        $this->assertSame(3400, $clusters[0]['end_ms']);
    }

    public function test_a_gap_past_the_padding_splits_into_two_clusters(): void
    {
        // end 1200, next start 3201 -> gap 2001 > padding, splits.
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(3201, 3400, 'declarative', 'planting'),
        ], 2000);

        $this->assertCount(2, $clusters);
        $this->assertSame(1200, $clusters[0]['end_ms']);
        $this->assertSame(3201, $clusters[1]['start_ms']);
    }

    public function test_hits_across_both_categories_make_a_compound_event(): void
    {
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(1500, 1700, 'declarative', 'planting'),
        ], 2000);

        $this->assertCount(1, $clusters);
        $this->assertSame(CommEvent::TYPE_COMPOUND, $clusters[0]['communication_type']);
        $this->assertFalse($clusters[0]['is_redundant']);
    }

    public function test_a_repeated_category_flags_the_cluster_redundant(): void
    {
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(1500, 1700, 'informative', 'there'),
        ], 2000);

        $this->assertSame(CommEvent::TYPE_INFORMATIVE, $clusters[0]['communication_type']);
        $this->assertTrue($clusters[0]['is_redundant']);
    }

    public function test_a_repeated_keyword_flags_the_cluster_redundant(): void
    {
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(1500, 1700, 'declarative', 'planting'),
            $this->hit(1800, 2000, 'declarative', 'planting'),
        ], 2000);

        $this->assertSame(CommEvent::TYPE_COMPOUND, $clusters[0]['communication_type']);
        $this->assertTrue($clusters[0]['is_redundant']);
    }

    public function test_event_span_uses_the_earliest_start_and_latest_end_across_hits(): void
    {
        // Second hit (by start) ends latest; a short trailing hit must not
        // truncate the span.
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 1200, 'informative', 'here'),
            $this->hit(1300, 5000, 'declarative', 'planting'),
            $this->hit(1400, 1600, 'informative', 'there'),
        ], 2000);

        $this->assertCount(1, $clusters);
        $this->assertSame(1000, $clusters[0]['start_ms']);
        $this->assertSame(5000, $clusters[0]['end_ms']);
    }

    public function test_a_long_early_hit_keeps_a_later_hit_in_the_same_cluster(): void
    {
        // Gap is measured to the running cluster end (5000), not the previous
        // hit's end (1600), so the third hit stays in.
        $clusters = CommEventClusterer::cluster([
            $this->hit(1000, 5000, 'informative', 'here'),
            $this->hit(1400, 1600, 'informative', 'there'),
            $this->hit(6500, 6700, 'declarative', 'planting'),
        ], 2000);

        $this->assertCount(1, $clusters);
        $this->assertSame(6700, $clusters[0]['end_ms']);
    }

    public function test_unordered_hits_are_sorted_before_clustering(): void
    {
        $clusters = CommEventClusterer::cluster([
            $this->hit(5000, 5200, 'informative', 'late'),
            $this->hit(1000, 1200, 'informative', 'here'),
        ], 2000);

        $this->assertCount(2, $clusters);
        $this->assertSame(1000, $clusters[0]['start_ms']);
        $this->assertSame(5000, $clusters[1]['start_ms']);
    }
}
