<?php

namespace Tests\Unit\Support;

use App\Support\GameStateAlignmentAssessor;
use PHPUnit\Framework\TestCase;

/**
 * The pure assessment: given a communication-event span, its normalized
 * keywords, the session's game events and the alignment window, decide the
 * assessment (possibly_positive / possibly_negative / neutral, or no row), the
 * narrated body, and the referenced game-event ids (see
 * docs/adr/0008-game-state-alignment.md, 2026-09-07 amendment). No Eloquent, so
 * the window, nearest-wins and body rules can be unit-tested in isolation, like
 * CommEventClusterer.
 */
class GameStateAlignmentAssessorTest extends TestCase
{
    private const WINDOW = 5000;

    /**
     * @param  list<array<string, mixed>>  $gameEvents
     * @param  list<string>  $keywords
     * @return array{assessment: string, body: string, game_event_ids: list<int>}|null
     */
    private function assess(array $keywords, array $gameEvents, int $spanStart = 20000, int $spanEnd = 21000): ?array
    {
        return GameStateAlignmentAssessor::assess($spanStart, $spanEnd, $keywords, $gameEvents, self::WINDOW);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'type' => 'kill',
            'side' => 'ally',
            'match_time_ms' => 21500,
            'note' => null,
            'raw' => null,
        ], $overrides);
    }

    public function test_an_unmapped_keyword_with_no_game_event_in_window_is_not_annotated(): void
    {
        $this->assertNull($this->assess(['rotate'], [
            $this->event(['match_time_ms' => 40000]),
        ]));
    }

    public function test_an_unmapped_keyword_takes_its_sign_from_the_nearest_in_window_event(): void
    {
        $favourable = $this->assess(['rotate'], [
            $this->event(['id' => 7, 'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 22000]),
        ]);
        $this->assertSame('possibly_positive', $favourable['assessment']);
        $this->assertSame([7], $favourable['game_event_ids']);
        $this->assertStringNotContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $favourable['body']);

        $unfavourable = $this->assess(['rotate'], [
            $this->event(['id' => 8, 'type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 21500]),
            $this->event(['id' => 9, 'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 25000]),
        ]);
        $this->assertSame('possibly_negative', $unfavourable['assessment']);
        $this->assertSame([8, 9], $unfavourable['game_event_ids']);
        $this->assertStringContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $unfavourable['body']);

        $neutralValence = $this->assess(['rotate'], [
            $this->event(['id' => 10, 'type' => 'death', 'side' => 'enemy', 'match_time_ms' => 22000]),
        ]);
        $this->assertSame('neutral', $neutralValence['assessment']);
    }

    public function test_a_corroborating_favourable_event_is_possibly_positive_without_a_nudge(): void
    {
        $result = $this->assess(['planted'], [
            $this->event(['id' => 4, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21900]),
        ]);

        $this->assertSame('possibly_positive', $result['assessment']);
        $this->assertSame([4], $result['game_event_ids']);
        $this->assertStringNotContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $result['body']);
        $this->assertStringContainsString('spike_plant', $result['body']);
    }

    public function test_a_corroborating_unfavourable_event_is_possibly_negative_with_a_nudge(): void
    {
        // "planted" corroborated by an enemy spike_plant: the call matched, but
        // the game state it matched does not favour us.
        $result = $this->assess(['planted'], [
            $this->event(['id' => 5, 'type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 21900]),
        ]);

        $this->assertSame('possibly_negative', $result['assessment']);
        $this->assertStringContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $result['body']);
    }

    public function test_a_contradiction_pair_event_is_possibly_negative_with_a_nudge(): void
    {
        $result = $this->assess(['planted'], [
            $this->event(['id' => 9, 'type' => 'spike_defuse', 'side' => 'ally', 'match_time_ms' => 22000]),
        ]);

        $this->assertSame('possibly_negative', $result['assessment']);
        $this->assertSame([9], $result['game_event_ids']);
        $this->assertStringContainsString('contradicts it', $result['body']);
        $this->assertStringContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $result['body']);
    }

    public function test_the_contradiction_pair_is_side_specific(): void
    {
        // A spike_plant claim is only contradicted by an ally spike_defuse; an
        // enemy spike_defuse is an unrelated kind, so the claim is neutral.
        $result = $this->assess(['planted'], [
            $this->event(['id' => 3, 'type' => 'spike_defuse', 'side' => 'enemy', 'match_time_ms' => 22000]),
        ]);

        $this->assertSame('neutral', $result['assessment']);
    }

    public function test_when_both_corroborate_and_contradict_the_nearest_to_the_span_decides(): void
    {
        $corroborateNearer = $this->assess(['planted'], [
            $this->event(['id' => 1, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21200]),
            $this->event(['id' => 2, 'type' => 'spike_defuse', 'side' => 'ally', 'match_time_ms' => 24000]),
        ]);

        $this->assertSame('possibly_positive', $corroborateNearer['assessment']);
        $this->assertSame([1, 2], $corroborateNearer['game_event_ids']);

        $contradictNearer = $this->assess(['planted'], [
            $this->event(['id' => 1, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 25000]),
            $this->event(['id' => 2, 'type' => 'spike_defuse', 'side' => 'ally', 'match_time_ms' => 21300]),
        ]);

        $this->assertSame('possibly_negative', $contradictNearer['assessment']);
        $this->assertSame([2, 1], $contradictNearer['game_event_ids']);
    }

    public function test_a_mapped_keyword_with_only_an_unrelated_event_is_neutral_but_still_narrates_it(): void
    {
        $result = $this->assess(['planted'], [
            $this->event(['id' => 6, 'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 22000]),
        ]);

        $this->assertSame('neutral', $result['assessment']);
        $this->assertSame([6], $result['game_event_ids']);
        $this->assertStringNotContainsString(GameStateAlignmentAssessor::REVIEW_NUDGE, $result['body']);
    }

    public function test_a_mapped_keyword_with_nothing_in_the_window_is_neutral_with_no_referenced_events(): void
    {
        $result = $this->assess(['planted'], [
            $this->event(['id' => 6, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 40000]),
        ]);

        $this->assertSame('neutral', $result['assessment']);
        $this->assertSame([], $result['game_event_ids']);
    }

    public function test_the_window_is_inclusive_of_its_edges_and_excludes_just_outside(): void
    {
        $onEdge = $this->assess(['planted'], [
            $this->event(['id' => 1, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21000 + self::WINDOW]),
        ]);
        $this->assertSame('possibly_positive', $onEdge['assessment']);
        $this->assertSame([1], $onEdge['game_event_ids']);

        $justOutside = $this->assess(['planted'], [
            $this->event(['id' => 1, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21000 + self::WINDOW + 1]),
        ]);
        $this->assertSame('neutral', $justOutside['assessment']);
        $this->assertSame([], $justOutside['game_event_ids']);
    }

    public function test_a_death_event_note_names_who_died_in_the_body(): void
    {
        $result = $this->assess(['died'], [
            $this->event(['id' => 2, 'type' => 'death', 'side' => 'ally', 'match_time_ms' => 22000, 'note' => 'Jett']),
        ]);

        $this->assertSame('possibly_negative', $result['assessment']);
        $this->assertStringContainsString('Jett', $result['body']);
    }

    public function test_the_body_caps_the_listed_events_and_counts_the_remainder(): void
    {
        $result = $this->assess(['down'], [
            $this->event(['id' => 1, 'type' => 'kill', 'match_time_ms' => 21100]),
            $this->event(['id' => 2, 'type' => 'kill', 'match_time_ms' => 21200]),
            $this->event(['id' => 3, 'type' => 'kill', 'match_time_ms' => 21300]),
            $this->event(['id' => 4, 'type' => 'kill', 'match_time_ms' => 21400]),
            $this->event(['id' => 5, 'type' => 'kill', 'match_time_ms' => 21500]),
        ]);

        $this->assertSame('possibly_positive', $result['assessment']);
        $this->assertSame([1, 2, 3, 4, 5], $result['game_event_ids']);
        $this->assertStringContainsString('2 more', $result['body']);
    }

    public function test_ids_after_the_decider_are_ordered_by_absolute_distance_to_the_span(): void
    {
        $result = $this->assess(['planted'], [
            $this->event(['id' => 10, 'type' => 'kill', 'match_time_ms' => 24000]),
            $this->event(['id' => 11, 'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21100]),
            $this->event(['id' => 12, 'type' => 'kill', 'match_time_ms' => 21500]),
        ]);

        // Decider (the corroborating spike_plant) first, then the rest nearest-first.
        $this->assertSame([11, 12, 10], $result['game_event_ids']);
    }
}
