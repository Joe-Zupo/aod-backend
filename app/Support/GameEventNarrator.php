<?php

namespace App\Support;

/**
 * Shared phrasing for game events in a generated annotation body. Both
 * GameStateAlignmentAssessor (docs/adr/0008) and DeadAirDetector (docs/adr/0009)
 * name the same events the same way; only the offset wording around each clause
 * ("900 ms later" for a callout, "1.1s in" for a silent period) differs and
 * stays with each caller.
 *
 * An event is the associative shape the jobs pass down:
 * `array{type: string, side: ?string, note: ?string, raw: ?array<string, mixed>}`.
 */
class GameEventNarrator
{
    /**
     * How many events a list spells out before collapsing the rest to
     * "and N more".
     */
    public const DEFAULT_CAP = 3;

    /**
     * The deflection to the coach that closes a body pointing at a bad moment,
     * keeping the annotation descriptive rather than a verdict. Every dead-air
     * body and every `possibly_negative` alignment body ends with it.
     */
    public const REVIEW_NUDGE = 'Consider reviewing this moment.';

    /**
     * Milliseconds as a trimmed second count: 5000 -> "5", 1500 -> "1.5".
     */
    public static function seconds(int $ms): string
    {
        return rtrim(rtrim(number_format($ms / 1000, 1), '0'), '.');
    }

    /**
     * The player behind a kill / death: `note` if it carries one, else a
     * name-ish key on the preserved `raw` payload, else null when the feed
     * named nobody (ADR 0008, 2026-09-07 amendment).
     *
     * @param  array{note?: ?string, raw?: ?array<string, mixed>}  $event
     */
    public static function actorName(array $event): ?string
    {
        $note = is_string($event['note'] ?? null) ? trim($event['note']) : '';

        if ($note !== '') {
            return $note;
        }

        foreach (['actor', 'player', 'agent', 'name'] as $key) {
            $value = $event['raw'][$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * The "what happened" half of an event phrase, with no offset:
     * "an ally kill", "Jett died", "ally spike_plant", "the round was won".
     *
     * @param  array{type: string, side: ?string, note: ?string, raw: ?array<string, mixed>}  $event
     */
    public static function clause(array $event): string
    {
        $name = self::actorName($event);
        $side = $event['side'];

        return match ($event['type']) {
            'kill' => $name !== null ? "{$name} fragged" : ($side === 'ally' ? 'an ally kill' : 'an enemy kill'),
            'death' => $name !== null ? "{$name} died" : ($side === 'ally' ? 'an ally died' : 'an enemy died'),
            'spike_plant' => "{$side} spike_plant",
            'spike_defuse' => "{$side} spike_defuse",
            'round_win' => 'the round was won',
            'round_lost' => 'the round was lost',
            default => $event['type'],
        };
    }

    /**
     * A "; "-joined list of the first $cap phrases, any remainder collapsed to
     * "and N more". Empty in, empty out.
     *
     * @param  list<string>  $phrases
     */
    public static function list(array $phrases, int $cap = self::DEFAULT_CAP): string
    {
        $shown = array_slice($phrases, 0, $cap);
        $sentence = implode('; ', $shown);

        $remainder = count($phrases) - count($shown);

        if ($remainder > 0) {
            $sentence .= " and {$remainder} more";
        }

        return $sentence;
    }
}
