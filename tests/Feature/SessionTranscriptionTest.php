<?php

namespace Tests\Feature;

use App\Jobs\PollTranscription;
use App\Jobs\SubmitTranscription;
use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * The completion call now lands an in_progress session on `processing`, the
 * first state of the analysis pipeline, creates its Timeline, and fans out one
 * transcription job per stored AOD.
 */
class SessionTranscriptionTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake('local');
    }

    public function test_completing_a_session_moves_it_to_processing_not_completed(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_PROCESSING,
        ]);
    }

    public function test_completing_a_session_creates_its_timeline(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->assertDatabaseMissing('timelines', ['session_id' => $session->id]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->assertDatabaseHas('timelines', ['session_id' => $session->id]);
    }

    public function test_completing_a_session_creates_a_queued_transcript_and_job_per_aod(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->pair($players[1]),
            ]])
            ->assertOk();

        $this->assertDatabaseCount('transcripts', 2);

        foreach (AodRecord::all() as $record) {
            $this->assertDatabaseHas('transcripts', [
                'aod_record_id' => $record->id,
                'provider' => 'assemblyai',
                'provider_transcript_id' => null,
                'status' => Transcript::STATUS_QUEUED,
            ]);
        }

        Queue::assertPushed(SubmitTranscription::class, 2);
    }

    public function test_a_player_with_no_audio_gets_no_transcript(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                ['user_id' => $players[1]->id, 'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4')],
            ]])
            ->assertOk();

        $this->assertDatabaseCount('transcripts', 1);
        Queue::assertPushed(SubmitTranscription::class, 1);
    }

    public function test_submit_job_uploads_the_aod_and_submits_it_for_transcription(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/upload/abc']),
            '*/v2/transcript' => Http::response(['id' => 'txn_abc', 'status' => 'queued']),
        ]);
        Queue::fake([PollTranscription::class]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $transcript = Transcript::sole();
        $this->assertSame('txn_abc', $transcript->provider_transcript_id);
        $this->assertSame(Transcript::STATUS_PROCESSING, $transcript->status);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/upload'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v2/transcript')
            // The submit body must be JSON, not form-encoded: AssemblyAI rejects
            // a form-encoded transcript request with a 400.
            && $request->hasHeader('Content-Type', 'application/json')
            && $request['audio_url'] === 'https://cdn.assemblyai.com/upload/abc'
            && $request['language_detection'] === true
            && $request['speaker_labels'] === false
            && in_array('main', $request['word_boost'], true));

        Queue::assertPushed(PollTranscription::class, 1);
    }

    public function test_a_completed_poll_stores_the_transcript_fields_then_fetches_sentences_and_words(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/1']),
            '*/v2/transcript' => Http::response(['id' => 'txn_1', 'status' => 'queued']),
            '*/v2/transcript/txn_1' => Http::response([
                'id' => 'txn_1',
                'status' => 'completed',
                'text' => 'two mid. go a.',
                'language_code' => 'en',
                'confidence' => 0.9,
                'audio_duration' => 12,
                'words' => [
                    ['text' => 'two', 'start' => 100, 'end' => 300, 'confidence' => 0.98],
                    ['text' => 'mid', 'start' => 320, 'end' => 500, 'confidence' => 0.91],
                    ['text' => 'go', 'start' => 900, 'end' => 1000, 'confidence' => 0.8],
                    ['text' => 'a', 'start' => 1010, 'end' => 1100, 'confidence' => 0.7],
                ],
            ]),
            '*/v2/transcript/txn_1/sentences' => Http::response([
                'id' => 'txn_1',
                'confidence' => 0.9,
                'audio_duration' => 12,
                'sentences' => [
                    [
                        'text' => 'two mid.',
                        'start' => 100,
                        'end' => 500,
                        'confidence' => 0.945,
                        'words' => [
                            ['text' => 'two', 'start' => 100, 'end' => 300, 'confidence' => 0.98],
                            ['text' => 'mid', 'start' => 320, 'end' => 500, 'confidence' => 0.91],
                        ],
                    ],
                    [
                        'text' => 'go a.',
                        'start' => 900,
                        'end' => 1100,
                        'confidence' => 0.75,
                        'words' => [
                            ['text' => 'go', 'start' => 900, 'end' => 1000, 'confidence' => 0.8],
                            ['text' => 'a', 'start' => 1010, 'end' => 1100, 'confidence' => 0.7],
                        ],
                    ],
                ],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $transcript = Transcript::sole();
        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->status);
        $this->assertSame('two mid. go a.', $transcript->text);
        $this->assertSame('en', $transcript->language_code);
        $this->assertSame(12000, $transcript->audio_duration_ms);
        $this->assertEqualsWithDelta(0.9, $transcript->confidence, 0.0001);
        $this->assertIsArray($transcript->raw_response);

        $this->assertSame(2, $transcript->sentences()->count());
        $this->assertDatabaseHas('transcript_sentences', [
            'transcript_id' => $transcript->id, 'position' => 0, 'text' => 'two mid.',
            'start_ms' => 100, 'end_ms' => 500,
        ]);

        $firstSentence = $transcript->sentences()->where('position', 0)->sole();

        $this->assertSame(4, $transcript->words()->count());
        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id,
            'transcript_sentence_id' => $firstSentence->id,
            'position' => 0, 'sentence_position' => 0, 'word' => 'two',
            'start_ms' => 100, 'end_ms' => 300,
        ]);
        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id,
            'transcript_sentence_id' => $firstSentence->id,
            'position' => 1, 'sentence_position' => 1, 'word' => 'mid',
        ]);

        $secondSentence = $transcript->sentences()->where('position', 1)->sole();
        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id,
            'transcript_sentence_id' => $secondSentence->id,
            'position' => 2, 'sentence_position' => 0, 'word' => 'go',
        ]);
    }

    public function test_poll_loops_until_the_provider_finishes(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/3']),
            '*/v2/transcript' => Http::response(['id' => 'txn_3', 'status' => 'queued']),
            '*/v2/transcript/txn_3' => Http::sequence()
                ->push(['id' => 'txn_3', 'status' => 'processing'])
                ->push([
                    'id' => 'txn_3',
                    'status' => 'completed',
                    'text' => 'go',
                    'language_code' => 'en',
                    'words' => [['text' => 'go', 'start' => 10, 'end' => 90, 'confidence' => 0.9]],
                ]),
            '*/v2/transcript/txn_3/sentences' => Http::response([
                'id' => 'txn_3',
                'sentences' => [
                    [
                        'text' => 'go',
                        'start' => 10,
                        'end' => 90,
                        'confidence' => 0.9,
                        'words' => [['text' => 'go', 'start' => 10, 'end' => 90, 'confidence' => 0.9]],
                    ],
                ],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->assertSame(Transcript::STATUS_COMPLETED, Transcript::sole()->status);
        // upload, submit, poll (processing), poll (completed), sentences
        Http::assertSentCount(5);
    }

    public function test_show_is_blocked_with_409_while_the_session_is_processing(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This session is still processing.');
    }

    public function test_show_still_works_for_a_session_that_is_not_processing(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.session.id', $session->id);
    }

    public function test_the_index_reports_transcription_progress_for_a_processing_session(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/i']),
            '*/v2/transcript' => Http::sequence()
                ->push(['id' => 'txn_a', 'status' => 'queued'])
                ->push(['id' => 'txn_b', 'status' => 'queued']),
            '*/v2/transcript/txn_a' => Http::response([
                'id' => 'txn_a', 'status' => 'completed', 'language_code' => 'en', 'text' => 'x', 'words' => [],
            ]),
            '*/v2/transcript/txn_a/sentences' => Http::response([
                'id' => 'txn_a', 'sentences' => [],
            ]),
            '*/v2/transcript/txn_b' => Http::response([
                'id' => 'txn_b', 'status' => 'error', 'error' => 'bad audio',
            ]),
        ]);

        [$team, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->pair($players[1]),
            ]])
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertOk()
            ->assertJsonPath('data.past_sessions.0.id', $session->id)
            ->assertJsonPath('data.past_sessions.0.transcription.total', 2)
            ->assertJsonPath('data.past_sessions.0.transcription.completed', 1)
            ->assertJsonPath('data.past_sessions.0.transcription.failed', 1);
    }

    public function test_a_queuing_session_carries_no_transcription_block(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_QUEUING);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertOk()
            ->assertJsonMissingPath('data.live_session.transcription');
    }

    /**
     * An in_progress session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players].
     */
    private function recordingSession(int $players = 1): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');

        $users = [];
        for ($i = 0; $i < $players; $i++) {
            $user = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
            $this->addParticipant($session, $user, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
            $users[] = $user;
        }

        return [$team, $coach, $session, $users];
    }

    /**
     * @return array<string, mixed>
     */
    private function pair(User $player): array
    {
        return [
            'user_id' => $player->id,
            'audio' => UploadedFile::fake()->create('aod.mp3', 16, 'audio/mpeg'),
            'video' => UploadedFile::fake()->create('vod.mp4', 16, 'video/mp4'),
        ];
    }
}
