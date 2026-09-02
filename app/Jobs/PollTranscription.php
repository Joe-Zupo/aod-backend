<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Models\TranscriptWord;
use App\Services\AssemblyAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls one submitted transcript until AssemblyAI reports it done, then stores
 * the words and flips the row to completed. Re-dispatches itself with a delay
 * while the provider is still working, bounded by services.assemblyai.max_polls.
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
            $transcript->update([
                'status' => Transcript::STATUS_FAILED,
                'error' => 'Transcription timed out before the provider finished.',
            ]);

            return;
        }

        // Fetch first: a transient failure here throws and the queue retries the
        // job, and that retry must not have already burned a poll off the
        // budget. Only a poll that actually reached the provider counts.
        $remote = $client->getTranscript($transcript->provider_transcript_id);
        $transcript->increment('poll_count');

        $status = $remote['status'] ?? null;

        if ($status === 'error') {
            $transcript->update([
                'status' => Transcript::STATUS_FAILED,
                'error' => Str::limit($remote['error'] ?? 'The provider reported an error.', 255, ''),
                'raw_response' => $remote,
            ]);

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
            $transcript->update([
                'status' => Transcript::STATUS_FAILED,
                'language_code' => $language,
                'error' => "Detected language '{$language}' is not supported.",
                'raw_response' => $remote,
            ]);

            return;
        }

        DB::transaction(function () use ($transcript, $remote) {
            $transcript->words()->delete();

            $rows = [];
            foreach (array_values($remote['words'] ?? []) as $position => $word) {
                $rows[] = [
                    'transcript_id' => $transcript->id,
                    'position' => $position,
                    'word' => $word['text'],
                    'start_ms' => (int) $word['start'],
                    'end_ms' => (int) $word['end'],
                    'confidence' => (float) ($word['confidence'] ?? 0),
                ];
            }

            if ($rows !== []) {
                TranscriptWord::insert($rows);
            }

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
        });
    }

    public function failed(Throwable $e): void
    {
        $this->transcript->fresh()?->update([
            'status' => Transcript::STATUS_FAILED,
            'error' => Str::limit($e->getMessage(), 255, ''),
        ]);
    }
}
