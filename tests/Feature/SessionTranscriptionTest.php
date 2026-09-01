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
            && $request['audio_url'] === 'https://cdn.assemblyai.com/upload/abc'
            && $request['language_detection'] === true
            && $request['speaker_labels'] === false
            && in_array('main', $request['word_boost'], true));

        Queue::assertPushed(PollTranscription::class, 1);
    }

    public function test_a_completed_poll_stores_the_words_and_marks_the_transcript_completed(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/1']),
            '*/v2/transcript' => Http::response(['id' => 'txn_1', 'status' => 'queued']),
            '*/v2/transcript/txn_1' => Http::response([
                'id' => 'txn_1',
                'status' => 'completed',
                'text' => 'two mid',
                'language_code' => 'en',
                'confidence' => 0.9,
                'audio_duration' => 12,
                'words' => [
                    ['text' => 'two', 'start' => 100, 'end' => 300, 'confidence' => 0.98],
                    ['text' => 'mid', 'start' => 320, 'end' => 500, 'confidence' => 0.91],
                ],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $transcript = Transcript::sole();
        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->status);
        $this->assertSame('two mid', $transcript->text);
        $this->assertSame('en', $transcript->language_code);
        $this->assertSame(12000, $transcript->audio_duration_ms);
        $this->assertEqualsWithDelta(0.9, $transcript->confidence, 0.0001);
        $this->assertIsArray($transcript->raw_response);

        $this->assertSame(2, $transcript->words()->count());
        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id, 'position' => 0, 'word' => 'two',
            'start_ms' => 100, 'end_ms' => 300,
        ]);
        $this->assertDatabaseHas('transcript_words', [
            'transcript_id' => $transcript->id, 'position' => 1, 'word' => 'mid',
            'start_ms' => 320, 'end_ms' => 500,
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
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->assertSame(Transcript::STATUS_COMPLETED, Transcript::sole()->status);
        // upload, submit, poll (processing), poll (completed)
        Http::assertSentCount(4);
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

    public function test_transcribe_endpoint_reruns_only_the_failed_transcripts(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/r']),
            '*/v2/transcript' => Http::sequence()
                ->push(['id' => 'ok_1', 'status' => 'queued'])
                ->push(['id' => 'bad_1', 'status' => 'queued'])
                ->push(['id' => 'bad_2', 'status' => 'queued']),
            '*/v2/transcript/ok_1' => Http::response([
                'id' => 'ok_1', 'status' => 'completed', 'language_code' => 'en', 'text' => 'good',
                'words' => [['text' => 'good', 'start' => 0, 'end' => 50, 'confidence' => 0.9]],
            ]),
            '*/v2/transcript/bad_1' => Http::response(['id' => 'bad_1', 'status' => 'error', 'error' => 'bad audio']),
            '*/v2/transcript/bad_2' => Http::response([
                'id' => 'bad_2', 'status' => 'completed', 'language_code' => 'en', 'text' => 'recovered',
                'words' => [['text' => 'recovered', 'start' => 0, 'end' => 80, 'confidence' => 0.9]],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->pair($players[1]),
            ]])
            ->assertOk();

        // One completed, one failed.
        $this->assertSame(1, Transcript::where('status', Transcript::STATUS_COMPLETED)->count());
        $this->assertSame(1, Transcript::where('status', Transcript::STATUS_FAILED)->count());

        $completedId = Transcript::where('status', Transcript::STATUS_COMPLETED)->value('id');
        $completedUpdatedAt = Transcript::find($completedId)->updated_at;

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transcribe")
            ->assertOk();

        // The previously failed one recovered on the second pass with bad_2.
        $this->assertSame(2, Transcript::where('status', Transcript::STATUS_COMPLETED)->count());
        $this->assertSame(0, Transcript::where('status', Transcript::STATUS_FAILED)->count());
        // The already-completed transcript was left untouched.
        $this->assertEquals($completedUpdatedAt, Transcript::find($completedId)->updated_at);
    }

    public function test_transcribe_endpoint_is_a_no_op_when_nothing_failed(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/n']),
            '*/v2/transcript' => Http::response(['id' => 'txn_n', 'status' => 'queued']),
            '*/v2/transcript/txn_n' => Http::response([
                'id' => 'txn_n', 'status' => 'completed', 'language_code' => 'en', 'text' => 'x', 'words' => [],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);
        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transcribe")
            ->assertOk();

        $this->assertSame(1, Transcript::where('status', Transcript::STATUS_COMPLETED)->count());
    }

    public function test_transcribe_endpoint_rejects_a_session_that_is_not_processing(): void
    {
        [, $coach, $session] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transcribe")
            ->assertStatus(409);
    }

    public function test_a_player_cannot_hit_the_transcribe_endpoint(): void
    {
        Queue::fake();

        [, $coach, $session, $players] = $this->recordingSession(1);
        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->actingAs($players[0], 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transcribe")
            ->assertForbidden();
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
