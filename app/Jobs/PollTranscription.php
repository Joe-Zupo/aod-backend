<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\AssemblyAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Polls one submitted transcript until AssemblyAI reports it done, then stores
 * the transcript-level fields, flips the row to completed, and dispatches
 * FetchTranscriptSentences to ingest the sentence and word detail. Re-dispatches
 * itself with a delay while the provider is still working, bounded by
 * services.assemblyai.max_polls.
 */
class PollTranscription implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Transcript $transcript) {}

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

    public function handle(AssemblyAiClient $client): void
    {
        $transcript = $this->transcript->fresh();

        if (! $transcript || in_array($transcript->status, [Transcript::STATUS_COMPLETED, Transcript::STATUS_FAILED], true)) {
            return;
        }

        if ($transcript->poll_count >= (int) config('services.assemblyai.max_polls')) {
            $transcript->markFailed('Transcription timed out before the provider finished.');

            return;
        }

        // Fetch first: a transient failure here throws and the queue retries the
        // job, and that retry must not have already burned a poll off the
        // budget. Only a poll that actually reached the provider counts.
        $remote = $client->getTranscript($transcript->provider_transcript_id);
        $transcript->increment('poll_count');

        $status = $remote['status'] ?? null;

        if ($status === 'error') {
            $transcript->markFailed(
                $remote['error'] ?? 'The provider reported an error.',
                ['raw_response' => $remote],
            );

            return;
        }

        if ($status !== 'completed') {
            self::dispatch($transcript)
                ->delay(now()->addSeconds((int) config('services.assemblyai.poll_interval_seconds')));

            return;
        }

        $language = $remote['language_code'] ?? null;
        $accepted = (array) config('services.assemblyai.accepted_languages');

        if ($language !== null && ! in_array($language, $accepted, true)) {
            $transcript->markFailed(
                "Detected language '{$language}' is not supported.",
                ['language_code' => $language, 'raw_response' => $remote],
            );

            return;
        }

        // Word-level rows are no longer written here. They are ingested from the
        // sentences endpoint by FetchTranscriptSentences, so that words and
        // sentences are one coherent structure (see
        // docs/adr/0005-sentence-indexed-transcript-storage.md). This branch
        // stores the transcript-level fields and hands off.
        $transcript->update([
            'status' => Transcript::STATUS_COMPLETED,
            'text' => $remote['text'] ?? null,
            'language_code' => $remote['language_code'] ?? null,
            'confidence' => isset($remote['confidence']) ? (float) $remote['confidence'] : null,
            'audio_duration_ms' => isset($remote['audio_duration'])
                ? (int) round(((float) $remote['audio_duration']) * 1000)
                : null,
            'raw_response' => $remote,
            'error' => null,
        ]);

        FetchTranscriptSentences::dispatch($transcript);
    }

    public function failed(Throwable $e): void
    {
        $transcript = $this->transcript->fresh();

        // If the poll itself already succeeded (transcript completed), a later
        // failure — e.g. an inline FetchTranscriptSentences throw on the sync
        // queue — is not this job's to record. Don't clobber a good result.
        if (! $transcript || $transcript->status === Transcript::STATUS_COMPLETED) {
            return;
        }

        $transcript->markFailed($e->getMessage());
    }
}
