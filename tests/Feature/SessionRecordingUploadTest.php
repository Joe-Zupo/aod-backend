<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Self-service per-player media upload: POST /sessions/{session}/recording.
 * A recording participant delivers their own audio and/or video, independent
 * of the coach's completion call (docs/adr/0012-per-player-recording-uploads.md).
 */
class SessionRecordingUploadTest extends TestCase
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

    public function test_a_team_member_who_never_joined_the_session_gets_422(): void
    {
        [$team, $coach, $session] = $this->recordingSessionWithOnePlayer();
        $outsider = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
            ])
            ->assertStatus(422);
    }

    public function test_a_member_of_another_team_gets_404(): void
    {
        [, , $session] = $this->recordingSessionWithOnePlayer();

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
            ])
            ->assertNotFound();
    }

    public function test_uploading_requires_authentication(): void
    {
        [, , $session] = $this->recordingSessionWithOnePlayer();

        $this->postJson("/api/sessions/{$session->id}/recording", [
            'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
        ])->assertUnauthorized();
    }

    public function test_a_recording_player_uploads_both_audio_and_video(): void
    {
        [, , $session, $player, $participant] = $this->recordingSessionWithOnePlayer();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('aod.mp3', 16, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('vod.mp4', 16, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.aod.original_filename', 'aod.mp3')
            ->assertJsonPath('data.vod.original_filename', 'vod.mp4');

        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $participant->id]);
        $this->assertDatabaseHas('vod_records', ['session_participant_id' => $participant->id]);
        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$player->id}/aod.mp3");
        Storage::disk('local')->assertExists("session-recordings/{$session->id}/{$player->id}/vod.mp4");
    }

    public function test_audio_only_upload_creates_no_vod_record(): void
    {
        [, , $session, $player, $participant] = $this->recordingSessionWithOnePlayer();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('aod.wav', 16, 'audio/wav'),
            ])
            ->assertOk()
            ->assertJsonPath('data.vod', null);

        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $participant->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $participant->id]);
    }

    public function test_an_empty_body_is_rejected(): void
    {
        [, , $session, $player] = $this->recordingSessionWithOnePlayer();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors']]);
    }

    /**
     * @return array{0: Team, 1: User, 2: Session, 3: User, 4: SessionParticipant}
     */
    private function recordingSessionWithOnePlayer(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');

        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $participant = $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        return [$team, $coach, $session, $player, $participant];
    }
}
