<?php

namespace Tests\Feature;

use App\Events\SessionParticipantLeft;
use App\Events\SessionStatusChanged;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Session departure: a participant leaves through
 * POST /sessions/{session}/leave, and the rules that hang off a departure —
 * cancel when nobody is left, regress an in_progress session that has lost
 * its last player (docs/adr/0013-recording-control-and-departure.md).
 */
class SessionDepartureTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_player_leaves_a_queuing_session_and_their_row_is_stamped(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $participant = $this->addParticipant(
            $session,
            $player,
            'player',
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        );

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk()
            ->assertJsonPath('message', 'Left session.');

        $this->assertNotNull($participant->fresh()->left_at);
    }

    public function test_leaving_again_when_already_gone_is_idempotent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $participant = $this->addParticipant($session, $player, 'player');
        $participant->update(['left_at' => now()->subMinute()]);
        $leftAt = $participant->fresh()->left_at;

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        $this->assertTrue($leftAt->equalTo($participant->fresh()->left_at));
    }

    public function test_a_team_member_who_never_joined_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $bystander = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($bystander, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not a participant in this session.');
    }

    public function test_a_non_member_cannot_reach_the_endpoint(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertNotFound();
    }

    public function test_the_last_player_leaving_an_in_progress_session_regresses_it_to_queuing(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'queuing');

        $this->assertSame('queuing', $session->fresh()->status);
    }

    public function test_a_player_leaving_while_another_still_records_keeps_the_session_in_progress(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $leaver = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $leaver, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($leaver, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_the_only_coach_leaving_an_in_progress_session_leaves_it_in_progress(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_the_last_participant_leaving_an_in_progress_session_cancels_rather_than_regresses(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'cancelled');

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_the_last_participant_leaving_a_queuing_session_cancels_it(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_leaving_discards_the_players_uploaded_recordings(): void
    {
        Storage::fake('local');
        Queue::fake();

        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $leaver = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $stayer = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $leaverRow = $this->addParticipant($session, $leaver, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $stayer, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        foreach ([$leaver, $stayer] as $player) {
            $this->actingAs($player, 'sanctum')
                ->post("/api/sessions/{$session->id}/recording", [
                    'audio' => UploadedFile::fake()->create('a.m4a', 10, 'audio/mp4'),
                    'video' => UploadedFile::fake()->create('v.mp4', 10, 'video/mp4'),
                ])
                ->assertOk();
        }

        $storedPath = $leaverRow->fresh()->aodRecord->path;

        $this->actingAs($leaver, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk()
            ->assertJsonPath('data.discarded.audio', true)
            ->assertJsonPath('data.discarded.video', true);

        // Leaving `recording` discards what that player delivered, so their
        // audio is never transcribed and their files do not outlive the run
        // (docs/adr/0013-recording-control-and-departure.md).
        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $leaverRow->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $leaverRow->id]);
        Storage::disk('local')->assertMissing($storedPath);

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
            $leaverRow->fresh()->participant_status,
        );
    }

    public function test_cancelling_an_emptied_session_broadcasts_the_status_change(): void
    {
        Event::fake([SessionParticipantLeft::class, SessionStatusChanged::class]);

        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        Event::assertDispatched(
            SessionStatusChanged::class,
            fn (SessionStatusChanged $event) => $event->session->is($session)
                && $event->session->status === 'cancelled',
        );
    }
}
