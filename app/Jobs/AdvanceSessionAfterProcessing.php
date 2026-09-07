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
 * When the session has game events, one more gate stands before
 * `timeline_ready`: game-state alignment. This job dispatches
 * AssessGameStateAlignment and returns without advancing; that job re-dispatches
 * this one once its marker is set, and the next run advances (see
 * docs/adr/0008-game-state-alignment.md).
 */
class AdvanceSessionAfterProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Session $session) {}

    public function handle(): void
    {
        $outcome = DB::transaction(function () {
            $session = Session::whereKey($this->session->getKey())
                ->lockForUpdate()
                ->first(['id', 'status', 'game_alignment_assessed_at']);

            if (! $session || $session->status !== Session::STATUS_PROCESSING) {
                return 'noop';
            }

            $transcripts = $this->session->transcriptsQuery()->get(['status', 'comm_events_detected']);

            if ($transcripts->isEmpty()) {
                return 'noop';
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
                return 'noop';
            }

            // Alignment only gates a session that has game events, and only
            // until it has been assessed once.
            if ($session->game_alignment_assessed_at === null && $this->session->gameEvents()->exists()) {
                return 'assess';
            }

            $this->session->update(['status' => Session::STATUS_TIMELINE_READY]);

            return 'advanced';
        });

        if ($outcome === 'assess') {
            AssessGameStateAlignment::dispatch($this->session);

            return;
        }

        if ($outcome === 'advanced') {
            Broadcasting::safely(new SessionStatusChanged($this->session->refresh()));
        }
    }
}
