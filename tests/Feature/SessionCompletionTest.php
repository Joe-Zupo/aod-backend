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
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Completion of an in_progress Session: an active Coach delivers one recording
 * participant's audio + video in a single call, which stores the pair, moves
 * the Session in_progress -> completed, and sweeps every recording participant
 * to completed.
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
    }

    public function test_active_coach_completes_an_in_progress_session_with_a_players_recordings(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');
        $participant = $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('scrim.mp3', 2048, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('scrim.mp4', 8192, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_COMPLETED);

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_COMPLETED,
        ]);

        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $participant->id]);
        $this->assertDatabaseHas('vod_records', ['session_participant_id' => $participant->id]);

        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$player->id}/aod.mp3");
        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$player->id}/vod.mp4");
    }

    public function test_completing_sweeps_every_recording_participant_to_completed_and_leaves_the_coach_ready(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $uploader = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $other = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $uploader, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $other, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($uploader->id))
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $uploader->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $other->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $coach->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);
    }

    public function test_a_recording_player_who_uploaded_nothing_is_still_swept_to_completed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $uploader = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $silent = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $uploader, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $silentRow = $this->addParticipant($session, $silent, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($uploader->id))
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $silent->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $silentRow->id]);
    }

    public function test_a_recording_player_who_had_left_is_still_swept_to_completed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $uploader = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $gone = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $uploader, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $gone, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING)
            ->update(['left_at' => now()]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($uploader->id))
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $gone->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
    }

    public function test_a_missing_audio_file_is_rejected_and_the_session_stays_in_progress(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'user_id' => $player->id,
                'video' => UploadedFile::fake()->create('scrim.mp4', 2048, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['audio']]]);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('vod_records', 0);
    }

    public function test_a_missing_video_file_is_rejected_and_the_session_stays_in_progress(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('scrim.mp3', 2048, 'audio/mpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['video']]]);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('aod_records', 0);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('scrim.mp3', 2048, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('huge.mp4', 3_000_000, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['video']]]);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('notes.pdf', 64, 'application/pdf'),
                'video' => UploadedFile::fake()->create('scrim.mp4', 2048, 'video/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['audio']]]);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_user_who_is_not_a_participant_is_rejected(): void
    {
        [$team, $coach, $session] = $this->inProgressSessionWithRecordingPlayer();
        $stranger = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($stranger->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That participant is not recording in this session.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_coach_participant_is_rejected_as_the_recording_target(): void
    {
        [$team, $coach, $session] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($coach->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'That participant is not recording in this session.');
    }

    public function test_completing_a_queuing_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_QUEUING);
    }

    public function test_completing_an_already_completed_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_COMPLETED);
    }

    public function test_completing_a_cancelled_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_CANCELLED);
    }

    public function test_an_assistant_coach_who_did_not_create_the_session_can_complete_it(): void
    {
        [$team, $mainCoach] = $this->makeTeamWithMember('main_coach');
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $mainCoach);
        $session = $this->createSession($team, $mainCoach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_COMPLETED);
    }

    public function test_a_player_cannot_complete_a_session(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertForbidden();

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_a_coach_of_another_team_gets_404_completing_this_teams_session(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertNotFound();

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_completing_a_session_requires_authentication(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $this->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertUnauthorized();
    }

    public function test_a_failed_file_write_aborts_completion_and_leaves_the_session_in_progress(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        $disk->shouldReceive('delete')->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertStatus(500);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('aod_records', 0);
        $this->assertDatabaseCount('vod_records', 0);
        $this->assertDatabaseHas('session_participants', [
            'user_id' => $player->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        ]);
    }

    public function test_a_partial_file_write_deletes_the_recording_that_already_landed(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();
        $audioPath = "session-recordings/{$session->id}/{$player->id}/aod.mp3";

        // Audio write lands, the following video write fails: the audio file
        // that did land must be deleted on the way out.
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->twice()->andReturn($audioPath, false);
        $disk->shouldReceive('delete')->once()->with($audioPath);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertStatus(500);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
        $this->assertDatabaseCount('aod_records', 0);
        $this->assertDatabaseCount('vod_records', 0);
    }

    public function test_complete_decides_on_the_stored_status_not_the_loaded_one(): void
    {
        [$team, $coach, $session, $player] = $this->inProgressSessionWithRecordingPlayer();

        // A concurrent request cancelled this session after we loaded it.
        $stale = Session::find($session->id);
        Session::whereKey($session->id)->update(['status' => Session::STATUS_CANCELLED]);

        try {
            $stale->complete($player, UploadedFile::fake()->create('a.mp3', 8, 'audio/mpeg'), UploadedFile::fake()->create('v.mp4', 8, 'video/mp4'));
            $this->fail('Expected complete() to reject a session cancelled since it was loaded.');
        } catch (SessionTransitionException $e) {
            $this->assertSame('Only an in_progress session can be completed.', $e->getMessage());
        }

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_CANCELLED]);
        $this->assertDatabaseCount('aod_records', 0);
        $this->assertDatabaseCount('vod_records', 0);
    }

    /**
     * An in_progress session with one recording player. Returns
     * [$team, $coach, $session, $player].
     */
    private function inProgressSessionWithRecordingPlayer(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        return [$team, $coach, $session, $player];
    }

    /**
     * @return array<string, mixed>
     */
    private function recordings(int $userId): array
    {
        return [
            'user_id' => $userId,
            'audio' => UploadedFile::fake()->create('scrim.mp3', 2048, 'audio/mpeg'),
            'video' => UploadedFile::fake()->create('scrim.mp4', 8192, 'video/mp4'),
        ];
    }

    private function assertWrongStatusRejected(string $status): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, $status);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", $this->recordings($player->id))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only an in_progress session can be completed.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => $status]);
    }
}
