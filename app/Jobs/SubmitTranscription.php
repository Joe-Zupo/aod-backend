<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Services\AssemblyAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uploads one AOD to AssemblyAI and submits it for pre-recorded transcription,
 * then hands off to PollTranscription. One of these is dispatched per stored
 * AOD when a session is completed. Transient HTTP failures retry with backoff;
 * a run that exhausts its attempts leaves the transcript failed.
 */
class SubmitTranscription implements ShouldQueue
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

        // Only a still-queued transcript is submittable. A retry that re-runs
        // after a prior attempt already submitted (worker killed between the
        // submit and the status update, say) must not upload and submit a
        // second time and orphan the first provider transcript.
        if (! $transcript || $transcript->status !== Transcript::STATUS_QUEUED) {
            return;
        }

        $record = $transcript->aodRecord;

        $uploadUrl = $client->upload(Storage::disk($record->disk)->path($record->path));

        $keywords = $record->sessionParticipant->session->team->keywordList();

        $submission = $client->submitTranscript($uploadUrl, $keywords);

        $transcript->update([
            'provider_transcript_id' => $submission['id'],
            'status' => Transcript::STATUS_PROCESSING,
        ]);

        PollTranscription::dispatch($transcript)
            ->delay(now()->addSeconds((int) config('services.assemblyai.poll_interval_seconds')));
    }

    public function failed(Throwable $e): void
    {
        $this->transcript->fresh()?->update([
            'status' => Transcript::STATUS_FAILED,
            'error' => Str::limit($e->getMessage(), 255, ''),
        ]);
    }
}
