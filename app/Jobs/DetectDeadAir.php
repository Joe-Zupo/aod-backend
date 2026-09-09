<?php

namespace App\Jobs;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\TeamSettings;
use App\Models\Transcript;
use App\Support\DeadAirDetector;

/**
 * One pass over a session: compute the team-aggregate silent stretches longer
 * than the team's dead-air threshold, persist them to `dead_air_periods`, and
 * write a `dead_air` annotation on any period that has an uncalled game event
 * strictly inside it (see docs/adr/0009-dead-air-detection.md).
 *
 * Runs for every session, not only those with game events. Idempotent: deletes
 * and rewrites this session's periods and their annotations, stamps
 * `dead_air_detected_at`, and re-dispatches AdvanceSessionAfterProcessing.
 * Lifecycle is in SessionDetectionPass.
 */
class DetectDeadAir extends SessionDetectionPass
{
    protected function marker(): string
    {
        return 'dead_air_detected_at';
    }

    /**
     * @return array{thresholdMs: int, periods: list<array{start_ms: int, end_ms: int}>, gameEvents: list<array<string, mixed>>}
     */
    protected function gather(Session $session): array
    {
        $thresholdMs = (int) $session->team->ensureSettings()
            ->get(TeamSettings::SETTING_DEAD_AIR_THRESHOLD)
            ->setting_parameter;

        $windowMs = (int) $session->transcriptsQuery()
            ->where('status', Transcript::STATUS_COMPLETED)
            ->max('audio_duration_ms');

        $spans = CommEvent::query()
            ->whereIn('transcript_id', $session->transcriptsQuery()->select('id'))
            ->get(['start_ms', 'end_ms'])
            ->map(fn (CommEvent $event) => [(int) $event->start_ms, (int) $event->end_ms])
            ->all();

        // Ordered by match_time_ms (the gameEvents relation), so the interior
        // slice in write() stays chronological for both game_event_ids and body.
        $gameEvents = $session->gameEvents()->get()
            ->map(fn (GameEvent $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'side' => $event->side,
                'match_time_ms' => (int) $event->match_time_ms,
                'note' => $event->note,
                'raw' => $event->raw,
            ])
            ->all();

        return [
            'thresholdMs' => $thresholdMs,
            'periods' => DeadAirDetector::periods($spans, $windowMs, $thresholdMs),
            'gameEvents' => $gameEvents,
        ];
    }

    /**
     * @param  array{thresholdMs: int, periods: list<array{start_ms: int, end_ms: int}>, gameEvents: list<array<string, mixed>>}  $gathered
     */
    protected function write(Session $session, array $gathered): void
    {
        ['thresholdMs' => $thresholdMs, 'periods' => $periods, 'gameEvents' => $gameEvents] = $gathered;

        Annotation::query()
            ->where('topic', Annotation::TOPIC_DEAD_AIR)
            ->where('annotatable_type', (new DeadAirPeriod)->getMorphClass())
            ->whereIn('annotatable_id', DeadAirPeriod::query()->where('session_id', $session->getKey())->select('id'))
            ->delete();

        DeadAirPeriod::query()->where('session_id', $session->getKey())->delete();

        foreach ($periods as $span) {
            $period = DeadAirPeriod::create([
                'session_id' => $session->getKey(),
                'start_ms' => $span['start_ms'],
                'end_ms' => $span['end_ms'],
                'dead_air_threshold_ms' => $thresholdMs,
            ]);

            $interior = array_values(array_filter(
                $gameEvents,
                fn (array $event) => $event['match_time_ms'] > $span['start_ms']
                    && $event['match_time_ms'] < $span['end_ms'],
            ));

            if ($interior === []) {
                continue;
            }

            $period->annotations()->create([
                'user_id' => null,
                'topic' => Annotation::TOPIC_DEAD_AIR,
                'assessment' => null,
                'body' => DeadAirDetector::body($span['start_ms'], $span['end_ms'], $interior),
                'game_event_ids' => array_map(fn (array $event) => $event['id'], $interior),
                'alignment_window_ms' => null,
            ]);
        }
    }
}
