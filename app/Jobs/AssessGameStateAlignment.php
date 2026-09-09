<?php

namespace App\Jobs;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\TeamSettings;
use App\Support\GameStateAlignmentAssessor;
use Illuminate\Database\Eloquent\Collection;

/**
 * One pass over a session: for every communication event whose keyword makes a
 * checkable game-state claim, or that has a game event within the team's
 * game-alignment window, write a system annotation recording the assessment and
 * a short description of what happened nearby (see
 * docs/adr/0008-game-state-alignment.md and its 2026-09-07 amendment).
 *
 * Idempotent: deletes and rewrites this session's `game_state_alignment`
 * annotations, snapshots the window onto each row, stamps
 * `game_alignment_assessed_at`, and re-dispatches AdvanceSessionAfterProcessing.
 * Dispatched by AdvanceSessionAfterProcessing when a processing session has game
 * events and has not been assessed yet. Lifecycle is in SessionDetectionPass.
 */
class AssessGameStateAlignment extends SessionDetectionPass
{
    protected function marker(): string
    {
        return 'game_alignment_assessed_at';
    }

    /**
     * @return array{windowMs: int, gameEvents: list<array<string, mixed>>, commEvents: Collection<int, CommEvent>}
     */
    protected function gather(Session $session): array
    {
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

        return ['windowMs' => $windowMs, 'gameEvents' => $gameEvents, 'commEvents' => $commEvents];
    }

    /**
     * @param  array{windowMs: int, gameEvents: list<array<string, mixed>>, commEvents: Collection<int, CommEvent>}  $gathered
     */
    protected function write(Session $session, array $gathered): void
    {
        ['windowMs' => $windowMs, 'gameEvents' => $gameEvents, 'commEvents' => $commEvents] = $gathered;

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
    }
}
