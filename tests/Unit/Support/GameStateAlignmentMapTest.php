<?php

namespace Tests\Unit\Support;

use App\Support\GameStateAlignmentMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The static lookup from a normalized callout keyword to the game-event kind it
 * claims something about, and the narrow contradiction pairs (see
 * docs/adr/0008-game-state-alignment.md). Pure vocabulary, owned in code.
 */
class GameStateAlignmentMapTest extends TestCase
{
    #[DataProvider('mappedKeywords')]
    public function test_a_mapped_keyword_resolves_to_its_kind(string $keyword, string $kind): void
    {
        $this->assertSame($kind, GameStateAlignmentMap::kindFor($keyword));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mappedKeywords(): array
    {
        return [
            'planting is a spike_plant claim' => ['planting', 'spike_plant'],
            'planted is a spike_plant claim' => ['planted', 'spike_plant'],
            'plant is a spike_plant claim' => ['plant', 'spike_plant'],
            'defusing is a spike_defuse claim' => ['defusing', 'spike_defuse'],
            'defused is a spike_defuse claim' => ['defused', 'spike_defuse'],
            'defuse is a spike_defuse claim' => ['defuse', 'spike_defuse'],
            'down is a kill claim' => ['down', 'kill'],
            'dead is a kill claim' => ['dead', 'kill'],
            'killed is a kill claim' => ['killed', 'kill'],
            'traded is a kill claim' => ['traded', 'kill'],
            'frag is a kill claim' => ['frag', 'kill'],
            'died is a death claim' => ['died', 'death'],
            'lost is a death claim' => ['lost', 'death'],
            'lost-someone is a death claim' => ['lost-someone', 'death'],
        ];
    }

    public function test_an_unmapped_keyword_resolves_to_null(): void
    {
        $this->assertNull(GameStateAlignmentMap::kindFor('rotate'));
        $this->assertNull(GameStateAlignmentMap::kindFor('mid'));
        $this->assertNull(GameStateAlignmentMap::kindFor(''));
    }

    #[DataProvider('contradictionPairs')]
    public function test_a_kind_carries_its_contradiction_pair(string $kind, ?array $pair): void
    {
        $this->assertSame($pair, GameStateAlignmentMap::contradictionFor($kind));
    }

    /**
     * @return array<string, array{0: string, 1: ?array{type: string, side: string}}>
     */
    public static function contradictionPairs(): array
    {
        return [
            'a spike_plant claim is contradicted by an ally spike_defuse' => ['spike_plant', ['type' => 'spike_defuse', 'side' => 'ally']],
            'a spike_defuse claim is contradicted by an enemy spike_plant' => ['spike_defuse', ['type' => 'spike_plant', 'side' => 'enemy']],
            'a kill claim has no contradiction pair' => ['kill', null],
            'a death claim has no contradiction pair' => ['death', null],
        ];
    }
}
