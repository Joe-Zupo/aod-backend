<?php

namespace App\Support;

/**
 * The static lookup from a normalized callout keyword to the game-event kind it
 * makes a checkable claim about, plus the narrow contradiction pairs (see
 * docs/adr/0008-game-state-alignment.md). Pure vocabulary, owned in code rather
 * than configured, and unit-tested like GameEventValence.
 *
 * Input is a `normalized_keyword` as CalloutDetection stores it (lower-cased,
 * surrounding punctuation stripped). A keyword not named here carries no claim,
 * so a callout that only uses such keywords is never assessed.
 */
class GameStateAlignmentMap
{
    /**
     * Normalized keyword to mapped kind. Covers the default keyword lists plus
     * the frag / loss vocabulary a team is likely to add; an entry with no
     * backing keyword is simply never hit (ADR 0008, 2026-09-07 amendment). The
     * `death` row is inert against the default lists until a team adds one of
     * its keywords or the `team_keywords.implies_event_type` extension lands.
     *
     * @var array<string, string>
     */
    private const KEYWORD_KIND = [
        'planting' => 'spike_plant',
        'planted' => 'spike_plant',
        'plant' => 'spike_plant',
        'defusing' => 'spike_defuse',
        'defused' => 'spike_defuse',
        'defuse' => 'spike_defuse',
        'down' => 'kill',
        'dead' => 'kill',
        'killed' => 'kill',
        'traded' => 'kill',
        'frag' => 'kill',
        'died' => 'death',
        'lost' => 'death',
        'lost-someone' => 'death',
    ];

    /**
     * The one game-event kind and side that mutually excludes a claim of the
     * given kind. Kept to the two unambiguous spike-state pairs; `kill` and
     * `death` claims have no contradiction, since a "down" callout near an ally
     * death is not a contradiction (ADR 0008).
     *
     * @var array<string, array{type: string, side: string}>
     */
    private const CONTRADICTION = [
        'spike_plant' => ['type' => 'spike_defuse', 'side' => 'ally'],
        'spike_defuse' => ['type' => 'spike_plant', 'side' => 'enemy'],
    ];

    public static function kindFor(string $normalizedKeyword): ?string
    {
        return self::KEYWORD_KIND[$normalizedKeyword] ?? null;
    }

    /**
     * @return array{type: string, side: string}|null
     */
    public static function contradictionFor(string $kind): ?array
    {
        return self::CONTRADICTION[$kind] ?? null;
    }
}
