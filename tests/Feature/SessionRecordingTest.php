<?php

namespace Tests\Feature;

use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * A player starts and stops their own recording through
 * POST /sessions/{session}/start-recording and /stop-recording. Stopping drops
 * them back to needs_consent, so returning to the run means consenting again
 * (docs/adr/0013-recording-control-and-departure.md).
 */
class SessionRecordingTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_player_stopping_returns_to_needs_consent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $stopper = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $participant = $this->addParticipant(
            $session,
            $stopper,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        );

        $this->actingAs($stopper, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk()
            ->assertJsonPath('message', 'Recording stopped.');

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
            $participant->fresh()->participant_status,
        );
        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_a_stopped_player_consents_again_and_resumes_recording(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $returner = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $participant = $this->addParticipant(
            $session,
            $returner,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        );

        $this->actingAs($returner, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        $this->actingAs($returner, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertOk()
            ->assertJsonPath('message', 'Recording started.');

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            $participant->fresh()->participant_status,
        );
    }

    public function test_a_player_who_has_not_consented_cannot_record(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $unconsented = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant(
            $session,
            $unconsented,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        );

        $this->actingAs($unconsented, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Consent to this session before recording.');
    }

    public function test_starting_again_while_already_recording_is_idempotent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $participant = $this->addParticipant(
            $session,
            $player,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        );

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertOk();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            $participant->fresh()->participant_status,
        );
    }

    public function test_stopping_again_when_not_recording_is_idempotent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $stopped = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $participant = $this->addParticipant(
            $session,
            $stopped,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        );

        $this->actingAs($stopped, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
            $participant->fresh()->participant_status,
        );
    }

    public function test_a_coach_has_nothing_to_record(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A Coach does not record, so there is nothing to start or stop.');
    }

    public function test_recording_cannot_start_on_a_session_that_is_not_in_progress(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a recording session can start or stop a recording.');
    }

    public function test_a_departed_player_cannot_resume_recording(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $leaver = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $gone = $this->addParticipant($session, $leaver, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);
        $gone->update(['left_at' => now()]);

        $this->actingAs($leaver, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start-recording")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not a participant in this session.');
    }

    public function test_the_last_player_stopping_returns_the_session_to_the_lobby(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        foreach ([$one, $two] as $player) {
            $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        }

        $this->actingAs($one, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk();

        $this->assertSame('in_progress', $session->fresh()->status);

        $this->actingAs($two, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk();

        $this->assertSame('queuing', $session->fresh()->status);

        foreach ([$one, $two] as $player) {
            $this->assertDatabaseHas('session_participants', [
                'session_id' => $session->id,
                'user_id' => $player->id,
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
            ]);
        }
    }

    public function test_stopping_discards_what_that_player_uploaded(): void
    {
        Storage::fake('local');

        [$session, $stopper, $stayer] = $this->recordingPair();
        $this->upload($session, $stopper);
        $this->upload($session, $stayer);

        $stopperRow = $session->participants()->where('user_id', $stopper->id)->first();
        $stayerRow = $session->participants()->where('user_id', $stayer->id)->first();
        $storedPath = $stopperRow->aodRecord->path;

        $this->actingAs($stopper, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk()
            ->assertJsonPath('data.discarded.audio', true)
            ->assertJsonPath('data.discarded.video', true);

        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $stopperRow->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $stopperRow->id]);
        Storage::disk('local')->assertMissing($storedPath);

        // The player still recording keeps everything they delivered.
        $this->assertDatabaseHas('aod_records', ['session_participant_id' => $stayerRow->id]);
    }

    public function test_stopping_with_nothing_uploaded_reports_nothing_discarded(): void
    {
        Storage::fake('local');

        [$session, $stopper] = $this->recordingPair();

        $this->actingAs($stopper, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk()
            ->assertJsonPath('data.discarded.audio', false)
            ->assertJsonPath('data.discarded.video', false);
    }

    public function test_a_stopped_player_can_no_longer_upload(): void
    {
        Storage::fake('local');

        [$session, $stopper] = $this->recordingPair();

        $this->actingAs($stopper, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk();

        // Upload eligibility is `participant_status === recording`
        // (docs/adr/0012-per-player-recording-uploads.md), and stopping left it.
        $this->actingAs($stopper, 'sanctum')
            ->post("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('a.m4a', 10, 'audio/mp4'),
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not eligible to upload a recording for this session.');
    }

    public function test_the_last_player_stopping_destroys_the_whole_runs_recordings(): void
    {
        Storage::fake('local');
        Queue::fake();

        [$session, $one, $two] = $this->recordingPair();
        $this->upload($session, $one);
        $this->upload($session, $two);

        $paths = $session->participants()
            ->whereNotNull('participant_status')
            ->get()
            ->flatMap(fn ($row) => [$row->aodRecord?->path, $row->vodRecord?->path])
            ->filter()
            ->all();

        $this->assertCount(4, $paths);

        foreach ([$one, $two] as $player) {
            $this->actingAs($player, 'sanctum')
                ->postJson("/api/sessions/{$session->id}/stop-recording")
                ->assertOk();
        }

        // Accepted consequence: a team that all stops before the Coach completes
        // ends the run and keeps none of it
        // (docs/adr/0013-recording-control-and-departure.md).
        $this->assertSame('queuing', $session->fresh()->status);
        $this->assertSame(0, AodRecord::count());
        $this->assertSame(0, VodRecord::count());

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    /**
     * An in_progress session with a Coach and two recording players.
     *
     * @return array{0: Session, 1: User, 2: User}
     */
    private function recordingPair(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $one, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $two, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        return [$session, $one, $two];
    }

    private function upload(Session $session, User $player): void
    {
        $this->actingAs($player, 'sanctum')
            ->post("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('a.m4a', 10, 'audio/mp4'),
                'video' => UploadedFile::fake()->create('v.mp4', 10, 'video/mp4'),
            ])
            ->assertOk();
    }
}
