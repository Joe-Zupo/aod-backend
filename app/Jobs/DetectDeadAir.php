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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One pass over a session: compute the team-aggregate silent stretches longer
 * than the team's dead-air threshold, persist them to `dead_air_periods`, and
 * write a `dead_air` annotation on any period that has an uncalled game event
 * strictly inside it (see docs/adr/0009-dead-air-detection.md).
 *
 * Runs for every session, not only those with game events. Idempotent: under
 * the session lock it re-reads the marker and bails if set, otherwise deletes
 * and rewrites this session's periods and their annotations, sets
 * `app_sessions.dead_air_detected_at`, and re-dispatches
 * AdvanceSessionAfterProcessing so the fan-in can advance to `timeline_ready`.
 */
class DetectDeadAir implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Session $session) {}

    public function tries(): int
    {
        return (int) config('services.assemblyai.job_tries');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        $session = $this->session->fresh();

        if (! $session || $session->status !== Session::STATUS_PROCESSING) {
            return;
        }

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
        // slice below stays chronological for both game_event_ids and the body.
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

        $periods = DeadAirDetector::periods($spans, $windowMs, $thresholdMs);

        DB::transaction(function () use ($session, $periods, $gameEvents, $thresholdMs) {
            $locked = Session::whereKey($session->getKey())
                ->lockForUpdate()
                ->first(['id', 'dead_air_detected_at']);

            if (! $locked || $locked->dead_air_detected_at !== null) {
                return;
            }

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

            $session->forceFill(['dead_air_detected_at' => now()])->save();
        });

        AdvanceSessionAfterProcessing::dispatch($session);
    }

    /**
     * A permanent detection failure must not strand the session in `processing`:
     * the fan-in holds every session at this gate until the marker is set, and
     * `reanalyze()` needs `timeline_ready`, so nothing would ever retry. Degrade
     * to a timeline with no dead-air rows and let the fan-in advance, the same
     * call AssessGameStateAlignment makes for its own stage (see
     * docs/adr/0009-dead-air-detection.md).
     */
    public function failed(Throwable $e): void
    {
        $session = $this->session->fresh();

        if (! $session || $session->status !== Session::STATUS_PROCESSING) {
            return;
        }

        if ($session->dead_air_detected_at === null) {
            $session->forceFill(['dead_air_detected_at' => now()])->save();
        }

        AdvanceSessionAfterProcessing::dispatch($session);
    }
}
