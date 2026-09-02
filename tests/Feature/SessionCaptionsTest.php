<?php

namespace Tests\Feature;

use App\Jobs\FetchTranscriptSentences;
use App\Models\Session;
use App\Models\Transcript;
use App\Models\User;
use App\Services\AssemblyAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Sentence-indexed storage (FetchTranscriptSentences) and the per-player
 * captions read endpoint. See docs/adr/0005-sentence-indexed-transcript-storage.md.
 */
class SessionCaptionsTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function fetch(Transcript $transcript): void
    {
        (new FetchTranscriptSentences($transcript))->handle(app(AssemblyAiClient::class));
    }

    public function test_fetch_writes_sentences_and_sentence_tagged_words(): void
    {
        Http::fake(['*/v2/transcript/prov_ok/sentences' => Http::response([
            'id' => 'prov_ok',
            'sentences' => [
                [
                    'text' => 'planting a now.',
                    'start' => 1000,
                    'end' => 2000,
                    'confidence' => 0.9,
                    'words' => [
                        ['text' => 'planting', 'start' => 1000, 'end' => 1400, 'confidence' => 0.92],
                        ['text' => 'a', 'start' => 1450, 'end' => 1600, 'confidence' => 0.7],
                        ['text' => 'now', 'start' => 1650, 'end' => 2000, 'confidence' => 0.88],
                    ],
                ],
                [
                    'text' => 'rotating b.',
                    'start' => 5000,
                    'end' => 5800,
                    'confidence' => 0.8,
                    'words' => [
                        ['text' => 'rotating', 'start' => 5000, 'end' => 5500, 'confidence' => 0.81],
                        ['text' => 'b', 'start' => 5550, 'end' => 5800, 'confidence' => 0.6],
                    ],
                ],
            ],
        ])]);

        $transcript = Transcript::factory()->completed()->create([
            'provider_transcript_id' => 'prov_ok',
            'raw_response' => ['words' => [
                ['text' => 'planting', 'start' => 1000, 'end' => 1400, 'confidence' => 0.92],
                ['text' => 'a', 'start' => 1450, 'end' => 1600, 'confidence' => 0.7],
                ['text' => 'now', 'start' => 1650, 'end' => 2000, 'confidence' => 0.88],
                ['text' => 'rotating', 'start' => 5000, 'end' => 5500, 'confidence' => 0.81],
                ['text' => 'b', 'start' => 5550, 'end' => 5800, 'confidence' => 0.6],
            ]],
        ]);

        $this->fetch($transcript);

        $this->assertSame(2, $transcript->sentences()->count());
        $this->assertSame(5, $transcript->words()->count());

        $second = $transcript->sentences()->where('position', 1)->sole();
        $this->assertSame('rotating b.', $second->text);
        $this->assertSame(5000, $second->start_ms);

        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id,
            'transcript_sentence_id' => $second->id,
            'position' => 3,
            'sentence_position' => 0,
            'word' => 'rotating',
        ]);
    }

    public function test_fetch_on_an_empty_transcript_writes_no_sentences(): void
    {
        Http::fake(['*/v2/transcript/prov_empty/sentences' => Http::response([
            'id' => 'prov_empty',
            'sentences' => [],
        ])]);

        $transcript = Transcript::factory()->completed()->create([
            'provider_transcript_id' => 'prov_empty',
            'text' => '',
            'raw_response' => ['words' => []],
        ]);

        $this->fetch($transcript);

        $this->assertSame(0, $transcript->sentences()->count());
        $this->assertSame(0, $transcript->words()->count());
        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->fresh()->status);
    }

    public function test_fetch_is_skipped_for_a_failed_transcript(): void
    {
        Bus::fake();
        Http::fake();

        $transcript = Transcript::factory()->failed()->create([
            'provider_transcript_id' => 'prov_dead',
        ]);

        $this->fetch($transcript);

        Http::assertNothingSent();
        $this->assertSame(0, $transcript->sentences()->count());
    }

    public function test_fetch_logs_a_warning_when_sentence_words_diverge_from_raw_words(): void
    {
        Http::fake(['*/v2/transcript/prov_div/sentences' => Http::response([
            'id' => 'prov_div',
            'sentences' => [
                [
                    'text' => 'go',
                    'start' => 10,
                    'end' => 90,
                    'confidence' => 0.9,
                    'words' => [['text' => 'go', 'start' => 10, 'end' => 90, 'confidence' => 0.9]],
                ],
            ],
        ])]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'diverge'));

        $transcript = Transcript::factory()->completed()->create([
            'provider_transcript_id' => 'prov_div',
            'raw_response' => ['words' => [
                ['text' => 'go', 'start' => 10, 'end' => 90, 'confidence' => 0.9],
                ['text' => 'now', 'start' => 100, 'end' => 200, 'confidence' => 0.9],
            ]],
        ]);

        $this->fetch($transcript);

        // The job still completes and writes what the sentences endpoint gave.
        $this->assertSame(1, $transcript->sentences()->count());
        $this->assertSame(1, $transcript->words()->count());
    }

    public function test_a_rerun_rebuilds_sentences_and_words_wholesale(): void
    {
        Http::fake(['*/v2/transcript/prov_rerun/sentences' => Http::sequence()
            ->push([
                'id' => 'prov_rerun',
                'sentences' => [
                    ['text' => 'one two.', 'start' => 0, 'end' => 500, 'confidence' => 0.9, 'words' => [
                        ['text' => 'one', 'start' => 0, 'end' => 200, 'confidence' => 0.9],
                        ['text' => 'two', 'start' => 250, 'end' => 500, 'confidence' => 0.9],
                    ]],
                ],
            ])
            ->push([
                'id' => 'prov_rerun',
                'sentences' => [
                    ['text' => 'three.', 'start' => 0, 'end' => 300, 'confidence' => 0.8, 'words' => [
                        ['text' => 'three', 'start' => 0, 'end' => 300, 'confidence' => 0.8],
                    ]],
                ],
            ]),
        ]);

        $transcript = Transcript::factory()->completed()->create([
            'provider_transcript_id' => 'prov_rerun',
            'raw_response' => ['words' => []],
        ]);

        $this->fetch($transcript);
        $this->assertSame(2, $transcript->words()->count());

        $this->fetch($transcript);
        $this->assertSame(1, $transcript->sentences()->count());
        $this->assertSame(1, $transcript->words()->count());
        $this->assertSame('three.', $transcript->sentences()->sole()->text);
    }

    public function test_captions_endpoint_is_blocked_with_409_while_processing(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_PROCESSING);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/captions")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This session is still processing.');
    }

    public function test_captions_endpoint_requires_an_active_team_member(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_PROCESSING);

        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/captions")
            ->assertNotFound();
    }
}
