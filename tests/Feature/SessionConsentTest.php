<?php

namespace Tests\Feature;

use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Per-session consent: a player moves their own participant row
 * needs_consent -> ready through POST /sessions/{session}/consent, behind
 * the guard that only a current non-Coach participant can consent and only
 * while the session is still open.
 */
class SessionConsentTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_player_consents_and_their_row_becomes_ready(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk()
            ->assertJsonPath('message', 'Consent recorded.');

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);
    }

    public function test_consenting_again_when_already_ready_is_idempotent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);
    }

    public function test_a_coach_consenting_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A coach has nothing to consent to.');
    }

    public function test_consenting_after_recording_has_begun_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Consent can no longer be recorded for this session.');
    }

    public function test_a_member_who_never_joined_the_session_cannot_consent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You are not in this session.');
    }

    public function test_consenting_in_a_completed_session_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'completed');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Consent can no longer be recorded for this session.');
    }

    public function test_a_player_can_consent_while_the_session_is_in_progress(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);
        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_a_coach_of_another_team_gets_404_consenting_on_this_teams_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT);

        $outsider = User::factory()->create();
        $outsider->assignRole('Player');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $outsider, 'player', $outsider);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertNotFound();
    }

    public function test_consenting_requires_authentication(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');

        $this->postJson("/api/sessions/{$session->id}/consent")
            ->assertUnauthorized();
    }
}
