<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Start/cancel authority for a Session: any active Coach on the session's
 * team (not just its creator) drives the queuing -> in_progress -> cancelled
 * transitions, each behind its own guard.
 */
class SessionControlTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_active_coach_starts_a_queuing_session_with_a_joined_player(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'in_progress');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_starting_a_session_with_no_joined_player_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A session needs at least one player before it can start.');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_QUEUING,
        ]);
    }

    public function test_starting_a_session_that_is_already_in_progress_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a queuing session can be started.');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);
    }

    public function test_starting_a_completed_session_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_COMPLETED);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a queuing session can be started.');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_COMPLETED,
        ]);
    }

    public function test_starting_a_session_whose_only_player_has_left_is_rejected(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player')->update(['left_at' => now()]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A session needs at least one player before it can start.');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_QUEUING,
        ]);
    }

    public function test_an_assistant_coach_who_did_not_create_the_session_can_start_it(): void
    {
        [$team, $mainCoach] = $this->makeTeamWithMember('main_coach');
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $mainCoach);
        $session = $this->createSession($team, $mainCoach, 'queuing');
        $this->addParticipant($session, $mainCoach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk()
            ->assertJsonPath('data.session.status', 'in_progress');
    }

    public function test_a_player_cannot_start_a_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertForbidden();

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_QUEUING,
        ]);
    }

    public function test_a_coach_of_another_team_gets_404_starting_this_teams_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertNotFound();

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_QUEUING,
        ]);
    }

    public function test_starting_a_session_requires_authentication(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');

        $this->postJson("/api/sessions/{$session->id}/start")
            ->assertUnauthorized();
    }
}
