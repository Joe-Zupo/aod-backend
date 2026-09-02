<?php

namespace App\Jobs;

use App\Models\Transcript;
use App\Models\TranscriptSentence;
use App\Models\TranscriptWord;
use App\Services\AssemblyAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fetches one completed transcript's sentence segmentation from AssemblyAI's
 * GET /v2/transcript/{id}/sentences endpoint and, in one transaction, writes
 * `transcript_sentences` and the sentence-tagged `transcript_words`. The words
 * nested in the sentences response are authoritative; `raw_response` still holds
 * the original `words[]` for audit, and a divergence between the two logs a
 * warning rather than failing the job (see
 * docs/adr/0005-sentence-indexed-transcript-storage.md).
 *
 * Dispatched by PollTranscription once a transcript reaches `completed`. A
 * re-run rebuilds both tables wholesale from a fresh sentences call.
 */
class FetchTranscriptSentences implements ShouldQueue
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

        // Only a completed transcript has sentences to fetch. A failed one is
        // terminal and skipped; anything not yet completed is not ours to act
        // on.
        if (! $transcript || $transcript->status !== Transcript::STATUS_COMPLETED) {
            return;
        }

        $remote = $client->getSentences($transcript->provider_transcript_id);
        $sentences = array_values($remote['sentences'] ?? []);

        DB::transaction(function () use ($transcript, $sentences) {
            // Rebuild wholesale: a re-run after re-segmentation replaces every
            // row. Words cascade off their sentence, but a prior run's words
            // are cleared explicitly too in case any were left unlinked.
            $transcript->sentences()->delete();
            $transcript->words()->delete();

            $position = 0;

            foreach ($sentences as $sentencePosition => $sentence) {
                $row = TranscriptSentence::create([
                    'transcript_id' => $transcript->id,
                    'position' => $sentencePosition,
                    'text' => $sentence['text'] ?? '',
                    'start_ms' => (int) ($sentence['start'] ?? 0),
                    'end_ms' => (int) ($sentence['end'] ?? 0),
                    'confidence' => (float) ($sentence['confidence'] ?? 0),
                ]);

                $wordRows = [];

                foreach (array_values($sentence['words'] ?? []) as $sentenceWordPosition => $word) {
                    $wordRows[] = [
                        'transcript_id' => $transcript->id,
                        'transcript_sentence_id' => $row->id,
                        'position' => $position++,
                        'sentence_position' => $sentenceWordPosition,
                        'word' => $word['text'] ?? '',
                        'start_ms' => (int) ($word['start'] ?? 0),
                        'end_ms' => (int) ($word['end'] ?? 0),
                        'confidence' => (float) ($word['confidence'] ?? 0),
                    ];
                }

                if ($wordRows !== []) {
                    TranscriptWord::insert($wordRows);
                }
            }
        });

        $this->warnOnWordDivergence($transcript, $sentences);

        DetectCommEvents::dispatch($transcript);
    }

    /**
     * The words nested in the sentences response should match the flat `words[]`
     * kept in `raw_response`. A mismatch in count or in the ordered (start, end)
     * spans is logged for a human to look at; it is not a job failure.
     *
     * @param  array<int, array<string, mixed>>  $sentences
     */
    private function warnOnWordDivergence(Transcript $transcript, array $sentences): void
    {
        $rawWords = array_values($transcript->raw_response['words'] ?? []);

        $sentenceWords = [];
        foreach ($sentences as $sentence) {
            foreach (array_values($sentence['words'] ?? []) as $word) {
                $sentenceWords[] = $word;
            }
        }

        $spanOf = fn (array $words) => array_map(
            fn (array $word) => [(int) ($word['start'] ?? 0), (int) ($word['end'] ?? 0)],
            $words,
        );

        if (count($rawWords) === count($sentenceWords) && $spanOf($rawWords) === $spanOf($sentenceWords)) {
            return;
        }

        Log::warning('Transcript sentence words diverge from the raw words[] payload.', [
            'transcript_id' => $transcript->id,
            'raw_word_count' => count($rawWords),
            'sentence_word_count' => count($sentenceWords),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->transcript->fresh()?->markFailed('Sentence fetch failed: '.$e->getMessage());
    }
}
