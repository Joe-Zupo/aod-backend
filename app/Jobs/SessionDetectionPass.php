<?php

namespace App\Jobs;

use App\Models\Session;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The shared lifecycle for a per-session detection pass: game-state alignment
 * (ADR 0008) and dead-air detection (ADR 0009). One pass over a `processing`
 * session, gated by a session-level marker column, idempotent, re-dispatching
 * the fan-in when it is done.
 *
 * `handle()` gathers what the pass needs outside any lock, then in one
 * transaction re-reads the marker under the session lock and bails if it is
 * already set (the fan-in can dispatch the same pass more than once when
 * transcripts finish close together), otherwise writes the pass's rows and
 * stamps the marker. `failed()` degrades rather than stranding the session in
 * `processing`: it stamps the marker with no rows and lets the fan-in advance,
 * the same call DetectCommEvents makes for its own stage.
 *
 * Subclasses supply the marker column, the gather step, and the write step.
 */
abstract class SessionDetectionPass implements ShouldQueue
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

    /**
     * The `app_sessions` column this pass stamps when it has run once. The
     * fan-in gate and `Session::reanalyze()` key off the same name.
     */
    abstract protected function marker(): string;

    /**
     * Everything the pass needs from the session, read outside the lock. The
     * return is handed straight to write().
     *
     * @return array<string, mixed>
     */
    abstract protected function gather(Session $session): array;

    /**
     * Persist this pass's rows for the session. Runs inside the locked
     * transaction, only when the marker was still null; deletes and rewrites so
     * a re-run is idempotent.
     *
     * @param  array<string, mixed>  $gathered
     */
    abstract protected function write(Session $session, array $gathered): void;

    public function handle(): void
    {
        $session = $this->session->fresh();

        if (! $session || $session->status !== Session::STATUS_PROCESSING) {
            return;
        }

        $gathered = $this->gather($session);

        DB::transaction(function () use ($session, $gathered) {
            $locked = Session::whereKey($session->getKey())
                ->lockForUpdate()
                ->first(['id', $this->marker()]);

            if (! $locked || $locked->{$this->marker()} !== null) {
                return;
            }

            $this->write($session, $gathered);

            $session->forceFill([$this->marker() => now()])->save();
        });

        AdvanceSessionAfterProcessing::dispatch($session);
    }

    public function failed(Throwable $e): void
    {
        $session = $this->session->fresh();

        if (! $session || $session->status !== Session::STATUS_PROCESSING) {
            return;
        }

        if ($session->{$this->marker()} === null) {
            $session->forceFill([$this->marker() => now()])->save();
        }

        AdvanceSessionAfterProcessing::dispatch($session);
    }
}
