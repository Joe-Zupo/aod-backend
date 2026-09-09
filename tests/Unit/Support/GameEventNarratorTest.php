<?php

namespace Tests\Unit\Support;

use App\Support\GameEventNarrator;
use PHPUnit\Framework\TestCase;

/**
 * The shared game-event phrasing: trimmed seconds, the player-name lookup, the
 * per-event clause, and the capped "; "-joined list with an "and N more" tail.
 * Lifted from GameStateAlignmentAssessor so DeadAirDetector narrates the same
 * way (see docs/adr/0009-dead-air-detection.md).
 */
class GameEventNarratorTest extends TestCase
{
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
            'match_time_ms' => 21000,
            'note' => null,
            'raw' => null,
        ], $overrides);
    }

    public function test_seconds_trims_a_trailing_zero_and_dot(): void
    {
        $this->assertSame('5', GameEventNarrator::seconds(5000));
        $this->assertSame('1.5', GameEventNarrator::seconds(1500));
        $this->assertSame('0.9', GameEventNarrator::seconds(900));
        $this->assertSame('0', GameEventNarrator::seconds(0));
    }

    public function test_actor_name_prefers_the_note_then_a_raw_key_then_null(): void
    {
        $this->assertSame('Jett', GameEventNarrator::actorName($this->event(['note' => '  Jett  '])));
        $this->assertSame('Sova', GameEventNarrator::actorName($this->event(['raw' => ['player' => 'Sova']])));
        $this->assertNull(GameEventNarrator::actorName($this->event()));
        $this->assertNull(GameEventNarrator::actorName($this->event(['raw' => ['player' => '   ']])));
    }

    public function test_clause_names_a_killer_or_falls_back_to_a_side(): void
    {
        $this->assertSame('Reyna fragged', GameEventNarrator::clause($this->event(['type' => 'kill', 'note' => 'Reyna'])));
        $this->assertSame('an ally kill', GameEventNarrator::clause($this->event(['type' => 'kill', 'side' => 'ally'])));
        $this->assertSame('an enemy kill', GameEventNarrator::clause($this->event(['type' => 'kill', 'side' => 'enemy'])));
    }

    public function test_clause_covers_death_spike_and_round_events(): void
    {
        $this->assertSame('Jett died', GameEventNarrator::clause($this->event(['type' => 'death', 'note' => 'Jett'])));
        $this->assertSame('an ally died', GameEventNarrator::clause($this->event(['type' => 'death', 'side' => 'ally'])));
        $this->assertSame('ally spike_plant', GameEventNarrator::clause($this->event(['type' => 'spike_plant', 'side' => 'ally'])));
        $this->assertSame('enemy spike_defuse', GameEventNarrator::clause($this->event(['type' => 'spike_defuse', 'side' => 'enemy'])));
        $this->assertSame('the round was won', GameEventNarrator::clause($this->event(['type' => 'round_win', 'side' => null])));
        $this->assertSame('the round was lost', GameEventNarrator::clause($this->event(['type' => 'round_lost', 'side' => null])));
    }

    public function test_list_joins_with_semicolons(): void
    {
        $this->assertSame('one', GameEventNarrator::list(['one']));
        $this->assertSame('one; two; three', GameEventNarrator::list(['one', 'two', 'three']));
        $this->assertSame('', GameEventNarrator::list([]));
    }

    public function test_list_caps_and_counts_the_remainder(): void
    {
        $this->assertSame(
            'one; two; three and 2 more',
            GameEventNarrator::list(['one', 'two', 'three', 'four', 'five']),
        );
        $this->assertSame(
            'one; two and 3 more',
            GameEventNarrator::list(['one', 'two', 'three', 'four', 'five'], 2),
        );
    }
}
