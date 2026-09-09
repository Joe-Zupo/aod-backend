<?php

namespace App\Jobs;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\TeamSettings;
use App\Support\GameStateAlignmentAssessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One pass over a session: for every communication event whose keyword makes a
 * checkable game-state claim, or that has a game event within the team's
 * game-alignment window, write a system annotation recording the assessment and
 * a short description of what happened nearby (see
 * docs/adr/0008-game-state-alignment.md and its 2026-09-07 amendment).
 *
 * Idempotent: deletes and rewrites this session's `game_state_alignment`
 * annotations, snapshots the window onto each row, sets the session marker, and
 * re-dispatches AdvanceSessionAfterProcessing so the fan-in can advance the
 * session to `timeline_ready`. Dispatched by AdvanceSessionAfterProcessing when
 * a processing session has game events and has not been assessed yet.
 */
class AssessGameStateAlignment implements ShouldQueue
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

        $windowMs = (int) $session->team->ensureSettings()
            ->get(TeamSettings::SETTING_GAME_ALIGNMENT_WINDOW)
            ->setting_parameter;

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

        $commEvents = CommEvent::query()
            ->whereIn('transcript_id', $session->transcriptsQuery()->select('id'))
            ->with('calloutDetections')
            ->get();

        DB::transaction(function () use ($session, $commEvents, $gameEvents, $windowMs) {
            Annotation::query()
                ->where('topic', Annotation::TOPIC_GAME_STATE_ALIGNMENT)
                ->where('annotatable_type', (new CommEvent)->getMorphClass())
                ->whereIn('annotatable_id', $commEvents->modelKeys())
                ->delete();

            foreach ($commEvents as $commEvent) {
                $keywords = $commEvent->calloutDetections
                    ->sortBy('start_ms')
                    ->pluck('normalized_keyword')
                    ->all();

                $result = GameStateAlignmentAssessor::assess(
                    (int) $commEvent->start_ms,
                    (int) $commEvent->end_ms,
                    $keywords,
                    $gameEvents,
                    $windowMs,
                );

                if ($result === null) {
                    continue;
                }

                $commEvent->annotations()->create([
                    'user_id' => null,
                    'topic' => Annotation::TOPIC_GAME_STATE_ALIGNMENT,
                    'assessment' => $result['assessment'],
                    'body' => $result['body'],
                    'game_event_ids' => $result['game_event_ids'],
                    'alignment_window_ms' => $windowMs,
                ]);
            }

            $session->forceFill(['game_alignment_assessed_at' => now()])->save();
        });

        AdvanceSessionAfterProcessing::dispatch($session);
    }

    /**
     * A permanent assessment failure must not strand the session in `processing`:
     * the fan-in holds a game-event session at this gate until the marker is set,
     * and `reanalyze()` needs `timeline_ready`, so nothing would ever retry.
     * Degrade to an unassessed timeline instead — set the marker with no
     * annotations and let the fan-in advance, the same "one dead mic never
     * freezes the review" call DetectCommEvents makes for its own stage (see
     * docs/adr/0008-game-state-alignment.md).
     */
    public function failed(Throwable $e): void
    {
        $session = $this->session->fresh();

        if (! $session || $session->status !== Session::STATUS_PROCESSING) {
            return;
        }

        if ($session->game_alignment_assessed_at === null) {
            $session->forceFill(['game_alignment_assessed_at' => now()])->save();
        }

        AdvanceSessionAfterProcessing::dispatch($session);
    }
}
