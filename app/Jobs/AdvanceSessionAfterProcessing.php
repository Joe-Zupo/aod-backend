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
 */
class AdvanceSessionAfterProcessing implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Session $session) {}

    public function handle(): void
    {
        $advanced = DB::transaction(function () {
            $status = Session::whereKey($this->session->getKey())->lockForUpdate()->value('status');

            if ($status !== Session::STATUS_PROCESSING) {
                return false;
            }

            $transcripts = $this->session->transcriptsQuery()->get(['status', 'comm_events_detected']);

            if ($transcripts->isEmpty()) {
                return false;
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
                return false;
            }

            $this->session->update(['status' => Session::STATUS_TIMELINE_READY]);

            return true;
        });

        if ($advanced) {
            Broadcasting::safely(new SessionStatusChanged($this->session->refresh()));
        }
    }
}
