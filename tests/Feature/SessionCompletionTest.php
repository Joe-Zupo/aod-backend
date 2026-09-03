<?php

namespace Tests\Feature;

use App\Exceptions\SessionTransitionException;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Completion of an in_progress Session: an active Coach delivers, in one call,
 * an audio and video slot for every recording player. The Session moves
 * in_progress -> completed and every recording participant is swept to
 * completed once at least one slot holds both an audio and a video. Slots may
 * be empty; a player who supplied nothing gets no record row.
 */
class SessionCompletionTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake('local');
        Queue::fake();
    }

    public function test_active_coach_completes_a_full_roster_when_one_player_has_both_files(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($players[1]),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_PROCESSING]);
        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $parts[$players[0]->id]->id]);
        $this->assertDatabaseHas('vod_records', ['session_participant_id' => $parts[$players[0]->id]->id]);
        $this->assertDatabaseCount('aod_records', 1);
        $this->assertDatabaseCount('vod_records', 1);

        foreach ($players as $player) {
            $this->assertDatabaseHas('session_participants', [
                'user_id' => $player->id,
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            ]);
        }
        $this->assertDatabaseHas('session_participants', [
            'user_id' => $coach->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);

        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$players[0]->id}/aod.mp3");
        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$players[0]->id}/vod.mp4");
    }

    public function test_an_audio_only_slot_creates_an_aod_record_and_no_vod_record(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                ['user_id' => $players[1]->id, 'audio' => UploadedFile::fake()->create('a.wav', 16, 'audio/wav')],
            ], 'game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
    }

    public function test_a_video_only_slot_creates_a_vod_record_and_no_aod_record(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                ['user_id' => $players[1]->id, 'video' => UploadedFile::fake()->create('v.webm', 16, 'video/webm')],
            ], 'game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('vod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
    }

    public function test_an_empty_slot_is_swept_to_completed_with_no_records(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($players[1]),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $parts[$players[1]->id]->id]);
    }

    public function test_completion_is_rejected_when_no_slot_holds_a_full_pair(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                ['user_id' => $players[0]->id, 'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg')],
                ['user_id' => $players[1]->id, 'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4')],
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one player must provide both an audio and a video recording.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('aod_records', 0);
        $this->assertDatabaseCount('vod_records', 0);
    }

    public function test_completion_is_rejected_when_a_recording_player_is_missing(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', "Completion must cover every recording player. Missing: {$players[1]->id}.");

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_completion_is_rejected_when_the_submission_names_a_non_recording_user(): void
    {
        [$team, $coach, $session, $players] = $this->recordingSession(2);
        $bystander = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($players[1]),
                $this->empty($bystander),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', "Completion must cover every recording player. Not recording: {$bystander->id}.");

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_mismatched_submission_reports_both_missing_and_unexpected_ids(): void
    {
        [$team, $coach, $session, $players] = $this->recordingSession(2);
        $bystander = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($bystander),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "Completion must cover every recording player. Missing: {$players[1]->id}. Not recording: {$bystander->id}.",
            );

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_player_who_left_mid_session_must_still_be_listed_and_is_swept(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);
        $session->participants()->where('user_id', $players[1]->id)->update(['left_at' => now()]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($players[1]),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
    }

    public function test_duplicate_user_ids_are_rejected(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->empty($players[0]),
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['players.0.user_id']]]);
    }

    public function test_players_must_be_a_non_empty_array(): void
    {
        [, $coach, $session] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['players']]]);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                [
                    'user_id' => $players[0]->id,
                    'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
                    'video' => UploadedFile::fake()->create('huge.mp4', 3_000_000, 'video/mp4'),
                ],
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['players.0.video']]]);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                [
                    'user_id' => $players[0]->id,
                    'audio' => UploadedFile::fake()->create('notes.pdf', 16, 'application/pdf'),
                    'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4'),
                ],
            ], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['players.0.audio']]]);
    }

    public function test_completing_a_queuing_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_QUEUING);
    }

    public function test_completing_an_already_processing_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_PROCESSING);
    }

    public function test_completing_a_cancelled_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_CANCELLED);
    }

    public function test_an_assistant_coach_who_did_not_create_the_session_can_complete_it(): void
    {
        [$team, $mainCoach, $session, $players] = $this->recordingSession(1);
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);
    }

    public function test_a_player_cannot_complete_a_session(): void
    {
        [, , $session, $players] = $this->recordingSession(1);

        $this->actingAs($players[0], 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertForbidden();

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_coach_of_another_team_gets_404_completing_this_teams_session(): void
    {
        [, , $session, $players] = $this->recordingSession(1);

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertNotFound();
    }

    public function test_completing_a_session_requires_authentication(): void
    {
        [, , $session, $players] = $this->recordingSession(1);

        $this->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertUnauthorized();
    }

    public function test_a_partial_file_write_deletes_every_file_the_call_already_wrote(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(1);
        $audioPath = "session-recordings/{$session->id}/{$players[0]->id}/aod.mp3";

        // Audio write lands, the video write fails: the audio that landed must
        // be deleted on the way out.
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->twice()->andReturn($audioPath, false);
        $disk->shouldReceive('delete')->once()->with($audioPath);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(500);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('aod_records', 0);
        $this->assertDatabaseCount('vod_records', 0);
        $this->assertDatabaseCount('game_events', 0);
    }

    public function test_complete_decides_on_the_stored_status_not_the_loaded_one(): void
    {
        [, , $session, $players] = $this->recordingSession(1);

        $stale = Session::find($session->id);
        Session::whereKey($session->id)->update(['status' => Session::STATUS_CANCELLED]);

        try {
            $stale->complete([[
                'user_id' => $players[0]->id,
                'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4'),
            ]]);
            $this->fail('Expected complete() to reject a session cancelled since it was loaded.');
        } catch (SessionTransitionException $e) {
            $this->assertSame('Only an in_progress session can be completed.', $e->getMessage());
        }

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_CANCELLED]);
        $this->assertDatabaseCount('aod_records', 0);
    }

    /**
     * An in_progress session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players, SessionParticipant[] $parts]
     * where $parts is keyed by user id.
     */
    private function recordingSession(int $players = 2, string $status = Session::STATUS_IN_PROGRESS): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, $status);
        $this->addParticipant($session, $coach, 'main_coach');

        $users = [];
        $parts = [];
        for ($i = 0; $i < $players; $i++) {
            $user = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
            $parts[$user->id] = $this->addParticipant($session, $user, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
            $users[] = $user;
        }

        return [$team, $coach, $session, $users, $parts];
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

    /**
     * @return array<string, mixed>
     */
    private function empty(User $player): array
    {
        return ['user_id' => $player->id];
    }

    private function assertWrongStatusRejected(string $status): void
    {
        [, $coach, $session, $players] = $this->recordingSession(1, $status);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])], 'game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only an in_progress session can be completed.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => $status]);
    }
}
