<?php

namespace App\Jobs;

use App\Models\Annotation;
use App\Models\CalloutDetection;
use App\Models\CommEvent;
use App\Models\Team;
use App\Models\TeamKeyword;
use App\Models\TeamSettings;
use App\Models\Transcript;
use App\Models\TranscriptWord;
use App\Support\CommEventClusterer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Detects Communication Events for one completed transcript: matches the team's
 * Team Keywords against its words, clusters the hits within the team's
 * Communication Event Padding, and writes `comm_events` + `callout_detections`,
 * replacing any prior rows. The padding value is snapshot onto each event.
 * Marks the transcript's detection done (including the zero-hit case) and
 * nudges the session toward `timeline_ready` (see
 * docs/adr/0006-communication-events.md).
 *
 * A dedicated job so keyword re-tuning can re-run it and the clustering can be
 * unit-tested in isolation.
 */
class DetectCommEvents implements ShouldQueue
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

    public function handle(): void
    {
        $transcript = $this->transcript->fresh();

        if (! $transcript) {
            return;
        }

        // A failed transcript is terminal and carries no detection; the session
        // fan-in already counts it as done.
        if ($transcript->status === Transcript::STATUS_FAILED) {
            return;
        }

        if ($transcript->status !== Transcript::STATUS_COMPLETED) {
            return;
        }

        $session = $transcript->session();
        $team = $session?->team;

        if (! $team) {
            return;
        }

        $paddingMs = (int) $team->ensureSettings()
            ->get(TeamSettings::SETTING_COMM_EVENT_PADDING)
            ->setting_parameter;

        $keywords = $this->keywordMap($team);

        $words = $transcript->words()->orderBy('position')->get();

        $hits = [];
        foreach ($words as $word) {
            $normalized = $this->normalize($word->word);

            if ($normalized === '' || ! isset($keywords[$normalized])) {
                continue;
            }

            $hits[] = [
                'transcript_word_id' => $word->id,
                'keyword' => $keywords[$normalized]['keyword'],
                'normalized_keyword' => $normalized,
                'category' => $keywords[$normalized]['category'],
                'start_ms' => (int) $word->start_ms,
                'end_ms' => (int) $word->end_ms,
                'confidence' => (float) $word->confidence,
            ];
        }

        $clusters = CommEventClusterer::cluster($hits, $paddingMs);

        DB::transaction(function () use ($transcript, $clusters, $paddingMs, $words) {
            // Any annotation hanging off a comm event about to be deleted would
            // be orphaned (polymorphic, no cascade), so clear them first. On a
            // re-analysis AssessGameStateAlignment writes fresh rows afterward.
            Annotation::query()
                ->where('annotatable_type', (new CommEvent)->getMorphClass())
                ->whereIn('annotatable_id', $transcript->commEvents()->select('id'))
                ->delete();

            $transcript->commEvents()->delete();

            foreach ($clusters as $cluster) {
                $event = CommEvent::create([
                    'transcript_id' => $transcript->id,
                    'communication_type' => $cluster['communication_type'],
                    'is_redundant' => $cluster['is_redundant'],
                    'start_ms' => $cluster['start_ms'],
                    'end_ms' => $cluster['end_ms'],
                    'content' => $this->spanText($words, $cluster['start_ms'], $cluster['end_ms']),
                    'padding_ms' => $paddingMs,
                ]);

                $rows = [];
                foreach ($cluster['hits'] as $hit) {
                    $rows[] = [
                        'comm_event_id' => $event->id,
                        'transcript_word_id' => $hit['transcript_word_id'],
                        'keyword' => $hit['keyword'],
                        'normalized_keyword' => $hit['normalized_keyword'],
                        'category' => $hit['category'],
                        'start_ms' => $hit['start_ms'],
                        'end_ms' => $hit['end_ms'],
                        'confidence' => $hit['confidence'],
                    ];
                }

                CalloutDetection::insert($rows);
            }

            $transcript->update(['comm_events_detected' => true]);
        });

        AdvanceSessionAfterProcessing::dispatch($session);
    }

    /**
     * A permanent detection failure must not strand the session in `processing`:
     * a completed transcript whose events never get detected keeps the fan-in
     * from ever passing. Degrade that mic to `failed` so the session still
     * advances (see docs/adr/0006-communication-events.md). If detection already
     * committed, a later inline failure is not ours to record.
     */
    public function failed(Throwable $e): void
    {
        $transcript = $this->transcript->fresh();

        if (! $transcript || $transcript->comm_events_detected) {
            return;
        }

        $transcript->markFailed('Communication-event detection failed: '.$e->getMessage());
    }

    /**
     * The team's keywords keyed by normalized form, each carrying the canonical
     * spelling and Category to snapshot onto a detection. A word configured in
     * both buckets resolves to whichever row is read last.
     *
     * @return array<string, array{keyword: string, category: string}>
     */
    private function keywordMap(Team $team): array
    {
        $rows = TeamKeyword::query()
            ->whereIn('team_settings_id', $team->settings()->select('id'))
            ->get(['keyword', 'category']);

        $map = [];
        foreach ($rows as $row) {
            $normalized = $this->normalize($row->keyword);

            if ($normalized === '') {
                continue;
            }

            $map[$normalized] = ['keyword' => $row->keyword, 'category' => $row->category];
        }

        return $map;
    }

    /**
     * Lower-case and strip surrounding punctuation / symbols, keeping any inside
     * the token (so `pre-firing` stays intact). No stemming (ADR 0001).
     */
    private function normalize(string $token): string
    {
        $stripped = preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $token);

        return mb_strtolower(trim((string) $stripped));
    }

    /**
     * The transcript text whose words fall within [start, end], space-joined.
     *
     * @param  Collection<int, TranscriptWord>  $words
     */
    private function spanText($words, int $start, int $end): string
    {
        return $words
            ->filter(fn ($word) => (int) $word->start_ms >= $start && (int) $word->end_ms <= $end)
            ->pluck('word')
            ->implode(' ');
    }
}
