<?php

namespace Tests\Unit\Support;

use App\Support\GameEventValence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Valence is a pure reading of (type, side), owned in code rather than
 * supplied by the payload (see docs/adr/0007-manual-game-event-ingest.md).
 */
class GameEventValenceTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_valence_of_a_game_event(string $type, ?string $side, string $expected): void
    {
        $this->assertSame($expected, GameEventValence::for($type, $side));
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function cases(): array
    {
        return [
            'our kill is favourable' => ['kill', 'ally', 'favourable'],
            'our spike plant is favourable' => ['spike_plant', 'ally', 'favourable'],
            'our spike defuse is favourable' => ['spike_defuse', 'ally', 'favourable'],
            'a round win is favourable' => ['round_win', null, 'favourable'],

            'an enemy kill is unfavourable' => ['kill', 'enemy', 'unfavourable'],
            'an enemy spike plant is unfavourable' => ['spike_plant', 'enemy', 'unfavourable'],
            'an enemy spike defuse is unfavourable' => ['spike_defuse', 'enemy', 'unfavourable'],
            'our death is unfavourable' => ['death', 'ally', 'unfavourable'],
            'a round loss is unfavourable' => ['round_lost', null, 'unfavourable'],

            'an enemy death is neutral' => ['death', 'enemy', 'neutral'],
        ];
    }
}
