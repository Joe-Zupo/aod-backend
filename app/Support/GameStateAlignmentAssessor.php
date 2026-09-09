<?php

namespace App\Support;

/**
 * The pure game-state alignment assessment for one communication event: given
 * its span, its normalized keywords, the session's game events and the
 * alignment window, decide the assessment, the narrated body and the referenced
 * game-event ids (see docs/adr/0008-game-state-alignment.md and its 2026-09-07
 * amendment). No Eloquent, so the window, nearest-wins and body rules stay
 * unit-testable in isolation, like CommEventClusterer.
 *
 * A row is produced when the callout's keyword maps to a kind, or when a game
 * event falls in the window, or both; a callout with neither returns null.
 * `assessment` is soft valence, not correspondence:
 *
 * - Keyword maps a kind: `possibly_positive` when the nearest corroborating
 *   game event favours the team, `possibly_negative` when it does not or when a
 *   contradiction-pair event fired, `neutral` when nothing corroborating or
 *   contradicting is in the window.
 * - Keyword maps nothing but a game event is nearby: the sign comes from the
 *   nearest in-window game event's valence, so a bad play near a callout that
 *   made no claim still reads `possibly_negative`.
 *
 * The `possibly_negative` body ends with the review nudge, the deflection to
 * the coach that keeps the annotation descriptive rather than a verdict.
 */
class GameStateAlignmentAssessor
{
    public const REVIEW_NUDGE = GameEventNarrator::REVIEW_NUDGE;

    public const ASSESSMENT_POSSIBLY_POSITIVE = 'possibly_positive';

    public const ASSESSMENT_POSSIBLY_NEGATIVE = 'possibly_negative';

    public const ASSESSMENT_NEUTRAL = 'neutral';

    /**
     * @param  list<string>  $normalizedKeywords  the callout's keywords, in callout order
     * @param  list<array{id: int, type: string, side: ?string, match_time_ms: int, note: ?string, raw: ?array<string, mixed>}>  $gameEvents  every game event on the session
     * @return array{assessment: string, body: string, game_event_ids: list<int>}|null
     */
    public static function assess(
        int $spanStartMs,
        int $spanEndMs,
        array $normalizedKeywords,
        array $gameEvents,
        int $windowMs,
    ): ?array {
        $kind = self::mappedKind($normalizedKeywords);

        $windowStart = $spanStartMs - $windowMs;
        $windowEnd = $spanEndMs + $windowMs;

        $inWindow = [];
        foreach ($gameEvents as $event) {
            $matchTime = (int) $event['match_time_ms'];

            if ($matchTime < $windowStart || $matchTime > $windowEnd) {
                continue;
            }

            $signed = match (true) {
                $matchTime < $spanStartMs => $matchTime - $spanStartMs,
                $matchTime > $spanEndMs => $matchTime - $spanEndMs,
                default => 0,
            };

            $inWindow[] = ['event' => $event, 'signed' => $signed, 'abs' => abs($signed)];
        }

        usort($inWindow, self::byNearness());

        if ($kind === null) {
            if ($inWindow === []) {
                return null;
            }

            $assessment = self::signFromValence($inWindow[0]['event']);
            $body = ucfirst(self::narrate($inWindow)).' near this callout.';

            if ($assessment === self::ASSESSMENT_POSSIBLY_NEGATIVE) {
                $body .= ' '.self::REVIEW_NUDGE;
            }

            return [
                'assessment' => $assessment,
                'body' => $body,
                'game_event_ids' => self::ids($inWindow),
            ];
        }

        $contradiction = GameStateAlignmentMap::contradictionFor($kind);

        $relevant = array_values(array_filter($inWindow, function (array $hit) use ($kind, $contradiction) {
            return self::corroborates($hit['event'], $kind)
                || self::contradicts($hit['event'], $contradiction);
        }));

        if ($relevant === []) {
            return [
                'assessment' => self::ASSESSMENT_NEUTRAL,
                'body' => self::neutralBody($kind, $windowMs, $inWindow),
                'game_event_ids' => self::ids($inWindow),
            ];
        }

        $decider = $relevant[0];
        $rest = array_values(array_filter(
            $inWindow,
            fn (array $hit) => $hit['event']['id'] !== $decider['event']['id'],
        ));

        if (! self::corroborates($decider['event'], $kind)) {
            return [
                'assessment' => self::ASSESSMENT_POSSIBLY_NEGATIVE,
                'body' => self::contradictedBody($kind, $decider, $rest),
                'game_event_ids' => array_merge([$decider['event']['id']], self::ids($rest)),
            ];
        }

        $assessment = self::signFromValence($decider['event']);

        return [
            'assessment' => $assessment,
            'body' => self::corroboratedBody($kind, $decider, $rest, $assessment),
            'game_event_ids' => array_merge([$decider['event']['id']], self::ids($rest)),
        ];
    }

    /**
     * @param  list<string>  $keywords
     */
    private static function mappedKind(array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $kind = GameStateAlignmentMap::kindFor($keyword);

            if ($kind !== null) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * A game event's valence as an assessment, read through the same table as
     * everywhere else (docs/adr/0007): favourable -> `possibly_positive`,
     * unfavourable -> `possibly_negative`, neutral valence (an enemy death) ->
     * `neutral`. Fed the nearest corroborating event for a mapped callout, and
     * the nearest in-window event for a narration-only row.
     *
     * @param  array{type: string, side: ?string}  $event
     */
    private static function signFromValence(array $event): string
    {
        return match (GameEventValence::for($event['type'], $event['side'])) {
            GameEventValence::FAVOURABLE => self::ASSESSMENT_POSSIBLY_POSITIVE,
            GameEventValence::UNFAVOURABLE => self::ASSESSMENT_POSSIBLY_NEGATIVE,
            default => self::ASSESSMENT_NEUTRAL,
        };
    }

    /**
     * @param  array{id: int, type: string, side: ?string, match_time_ms: int, note: ?string, raw: ?array<string, mixed>}  $event
     */
    private static function corroborates(array $event, string $kind): bool
    {
        return $event['type'] === $kind;
    }

    /**
     * @param  array{id: int, type: string, side: ?string, match_time_ms: int, note: ?string, raw: ?array<string, mixed>}  $event
     * @param  array{type: string, side: string}|null  $contradiction
     */
    private static function contradicts(array $event, ?array $contradiction): bool
    {
        return $contradiction !== null
            && $event['type'] === $contradiction['type']
            && $event['side'] === $contradiction['side'];
    }

    /**
     * Sort in-window hits nearest-first: by absolute distance to the span, ties
     * broken by the earlier match time, then the lower id.
     *
     * @return callable(array{event: array{id: int, match_time_ms: int}, abs: int}, array{event: array{id: int, match_time_ms: int}, abs: int}): int
     */
    private static function byNearness(): callable
    {
        return fn (array $a, array $b) => [$a['abs'], $a['event']['match_time_ms'], $a['event']['id']]
            <=> [$b['abs'], $b['event']['match_time_ms'], $b['event']['id']];
    }

    /**
     * @param  list<array{event: array{id: int}}>  $hits
     * @return list<int>
     */
    private static function ids(array $hits): array
    {
        return array_map(fn (array $hit) => $hit['event']['id'], $hits);
    }

    /**
     * @param  array{event: array<string, mixed>, signed: int}  $decider
     * @param  list<array{event: array<string, mixed>, signed: int}>  $rest
     */
    private static function corroboratedBody(string $kind, array $decider, array $rest, string $assessment): string
    {
        $body = "Callout mapped to {$kind}; ".self::narrate(array_merge([$decider], $rest)).'.';

        if ($assessment === self::ASSESSMENT_POSSIBLY_NEGATIVE) {
            $body .= ' '.self::REVIEW_NUDGE;
        }

        return $body;
    }

    /**
     * @param  array{event: array<string, mixed>, signed: int}  $decider
     * @param  list<array{event: array<string, mixed>, signed: int}>  $rest
     */
    private static function contradictedBody(string $kind, array $decider, array $rest): string
    {
        $body = "Callout mapped to {$kind}; ".self::describe($decider).' contradicts it';

        if ($rest !== []) {
            $body .= ', also nearby '.self::narrate($rest, GameEventNarrator::DEFAULT_CAP - 1);
        }

        return $body.'. '.self::REVIEW_NUDGE;
    }

    /**
     * @param  list<array{event: array<string, mixed>, signed: int}>  $inWindow
     */
    private static function neutralBody(string $kind, int $windowMs, array $inWindow): string
    {
        $body = "Callout mapped to {$kind}; no {$kind} within ".GameEventNarrator::seconds($windowMs).'s';

        if ($inWindow !== []) {
            $body .= ', but '.self::narrate($inWindow).' nearby';
        }

        return $body.'.';
    }

    /**
     * A "; "-joined description of the nearest hits, up to $cap of them, with
     * any remainder collapsed to "and N more".
     *
     * @param  list<array{event: array<string, mixed>, signed: int}>  $hits
     */
    private static function narrate(array $hits, int $cap = GameEventNarrator::DEFAULT_CAP): string
    {
        return GameEventNarrator::list(
            array_map(fn (array $hit) => self::describe($hit), $hits),
            $cap,
        );
    }

    /**
     * One game event as "<what> <when>", e.g. "ally spike_plant 900 ms later",
     * "Jett died 1.2s earlier", "an enemy kill during the callout". The "what"
     * is shared with dead air via GameEventNarrator; the signed offset is
     * alignment's own.
     *
     * @param  array{event: array{type: string, side: ?string, note: ?string, raw: ?array<string, mixed>}, signed: int}  $hit
     */
    private static function describe(array $hit): string
    {
        return trim(GameEventNarrator::clause($hit['event']).' '.self::offsetPhrase($hit['signed']));
    }

    private static function offsetPhrase(int $signedMs): string
    {
        if ($signedMs === 0) {
            return 'during the callout';
        }

        return GameEventNarrator::magnitude(abs($signedMs)).($signedMs > 0 ? ' later' : ' earlier');
    }
}
