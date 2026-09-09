<?php

namespace App\Jobs;

use App\Events\SessionStatusChanged;
use App\Models\Session;
use App\Models\Transcript;
use App\Support\Broadcasting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent fan-in: flips a session from `processing` to `timeline_ready` once
 * every transcript is terminal and every completed one has had its
 * communication events detected. A permanently `failed` transcript counts as
 * done, so one dead mic never freezes the review. Dispatched as each transcript
 * reaches a terminal state; a run that finds the session not ready just returns
 * and a later transcript's completion re-runs it (see
 * docs/adr/0006-communication-events.md).
 *
 * Two detection gates stand before `timeline_ready`. Dead-air detection runs for
 * every session; game-state alignment runs only when the session has game
 * events. When either marker is still null this job dispatches the missing job
 * or jobs and returns without advancing; each re-dispatches this one on
 * completion, and the run that finds both markers set advances (see
 * docs/adr/0008-game-state-alignment.md and docs/adr/0009-dead-air-detection.md).
 */
class AdvanceSessionAfterProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Session $session) {}

    public function handle(): void
    {
        $plan = DB::transaction(function () {
            $session = Session::whereKey($this->session->getKey())
                ->lockForUpdate()
                ->first(['id', 'status', 'game_alignment_assessed_at', 'dead_air_detected_at']);

            if (! $session || $session->status !== Session::STATUS_PROCESSING) {
                return null;
            }

            $transcripts = $this->session->transcriptsQuery()->get(['status', 'comm_events_detected']);

            if ($transcripts->isEmpty()) {
                return null;
            }

            $allTerminal = $transcripts->every(fn ($transcript) => in_array(
                $transcript->status,
                [Transcript::STATUS_COMPLETED, Transcript::STATUS_FAILED],
                true,
            ));

            $completedDetected = $transcripts
                ->where('status', Transcript::STATUS_COMPLETED)
                ->every(fn ($transcript) => (bool) $transcript->comm_events_detected);

            if (! $allTerminal || ! $completedDetected) {
                return null;
            }

            // Alignment only gates a session that has game events; dead air gates
            // every session. Each gate holds until its marker is set once.
            $assess = $session->game_alignment_assessed_at === null && $this->session->gameEvents()->exists();
            $detectDeadAir = $session->dead_air_detected_at === null;

            if ($assess || $detectDeadAir) {
                return ['assess' => $assess, 'dead_air' => $detectDeadAir, 'advanced' => false];
            }

            $this->session->update(['status' => Session::STATUS_TIMELINE_READY]);

            return ['assess' => false, 'dead_air' => false, 'advanced' => true];
        });

        if ($plan === null) {
            return;
        }

        if ($plan['assess']) {
            AssessGameStateAlignment::dispatch($this->session);
        }

        if ($plan['dead_air']) {
            DetectDeadAir::dispatch($this->session);
        }

        if ($plan['advanced']) {
            Broadcasting::safely(new SessionStatusChanged($this->session->refresh()));
        }
    }
}
