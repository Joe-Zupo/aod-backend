<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function makeTeamWithMember(string $memberRole, string $spatieRole = 'Coach'): array
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        $team = Team::factory()->create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $this->attachActiveMember($team, $user, $memberRole, $user);

        return [$team, $user];
    }

    private function attachActiveMember(Team $team, User $user, string $memberRole, User $decidedBy): void
    {
        $team->members()->attach($user, [
            'member_role' => $memberRole,
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
        ]);
    }

    private function makeAndAttachMember(Team $team, string $memberRole, string $spatieRole, User $decidedBy): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);
        $this->attachActiveMember($team, $user, $memberRole, $decidedBy);

        return $user;
    }

    private function createSession(Team $team, User $creator, string $status = 'queuing', string $name = 'Scrim vs Team B'): Session
    {
        return Session::factory()->for($team)->create([
            'created_by' => $creator->id,
            'session_name' => $name,
            'status' => $status,
        ]);
    }

    private function addParticipant(Session $session, User $user, string $role): SessionParticipant
    {
        return SessionParticipant::factory()->for($session)->create([
            'user_id' => $user->id,
            'participant_role' => $role,
        ]);
    }

    public function test_active_coach_can_create_a_session_and_its_timeline(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim vs Team B'])
            ->assertCreated()
            ->assertJsonPath('data.session.session_name', 'Scrim vs Team B')
            ->assertJsonPath('data.session.status', 'queuing');

        $this->assertDatabaseHas('app_sessions', [
            'team_id' => $team->id,
            'created_by' => $coach->id,
            'session_name' => 'Scrim vs Team B',
            'status' => 'queuing',
        ]);

        $session = $team->sessions()->first();
        $this->assertDatabaseHas('timelines', [
            'session_id' => $session->id,
        ]);
    }

    public function test_creating_a_session_automatically_joins_the_creating_coach_as_a_participant(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim vs Team B'])
            ->assertCreated()
            ->assertJsonCount(1, 'data.session.participants')
            ->assertJsonPath('data.session.participants.0.user_id', $coach->id)
            ->assertJsonPath('data.session.participants.0.username', $coach->username)
            ->assertJsonPath('data.session.participants.0.participant_role', 'main_coach');

        $session = $team->sessions()->first();
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $coach->id,
            'participant_role' => 'main_coach',
        ]);
    }

    public function test_session_creation_is_rejected_when_team_already_has_a_non_terminal_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $this->createSession($team, $coach, 'queuing', 'Existing scrim');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Second scrim'])
            ->assertStatus(422);

        $this->assertDatabaseCount('app_sessions', 1);
    }

    public function test_assistant_coach_can_create_a_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('assistant_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim'])
            ->assertCreated();
    }

    public function test_player_cannot_create_a_session(): void
    {
        [$team, $player] = $this->makeTeamWithMember('player', 'Player');

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim'])
            ->assertForbidden();

        $this->assertDatabaseCount('app_sessions', 0);
    }

    public function test_outsider_gets_404_creating_a_session_for_a_team_they_are_not_on(): void
    {
        [$team] = $this->makeTeamWithMember('main_coach');
        $outsider = User::factory()->create();
        $outsider->assignRole('Coach');

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim'])
            ->assertNotFound();
    }

    public function test_creating_a_session_for_an_unknown_team_returns_404(): void
    {
        [, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson('/api/teams/999999/sessions', ['session_name' => 'Scrim'])
            ->assertNotFound();
    }

    public function test_active_team_member_can_view_a_session_and_its_participants(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.session.session_name', 'Scrim vs Team B')
            ->assertJsonCount(1, 'data.session.participants');
    }

    public function test_outsider_gets_404_viewing_a_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertNotFound();
    }

    public function test_viewing_an_unknown_session_returns_404(): void
    {
        [, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/sessions/999999')
            ->assertNotFound();
    }

    public function test_active_team_member_can_join_a_queuing_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_role' => 'player',
        ]);
    }

    public function test_joining_twice_is_idempotent(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);

        $this->actingAs($coach, 'sanctum')->postJson("/api/sessions/{$session->id}/join")->assertOk();
        $this->actingAs($coach, 'sanctum')->postJson("/api/sessions/{$session->id}/join")->assertOk();

        $this->assertDatabaseCount('session_participants', 1);
    }

    public function test_rejoining_after_leaving_reactivates_the_participant(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->withToken($player->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertNotNull($session->participants()->where('user_id', $player->id)->first()->left_at);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk()
            ->assertJsonCount(2, 'data.session.participants');

        $this->assertDatabaseCount('session_participants', 2);
        $this->assertNull($session->participants()->where('user_id', $player->id)->first()->left_at);
    }

    public function test_duplicate_participant_insert_throws_the_exception_type_join_catches(): void
    {
        // Guards the assumption behind SessionController::join()'s race-safety fix:
        // a concurrent duplicate insert on session_participants must raise
        // UniqueConstraintViolationException specifically, since that's the only
        // exception type join() catches to stay idempotent under a real race.
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->addParticipant($session, $coach, 'main_coach');
    }

    public function test_logging_out_marks_the_user_as_left_but_keeps_the_session_open_while_a_coach_remains(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->withToken($player->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
        ]);
        $this->assertNotNull($session->participants()->where('user_id', $player->id)->first()->left_at);
        $this->assertSame('queuing', $session->fresh()->status);

        // The row is kept (not deleted), but a client checking the session
        // afterward must not see the departed player as a current participant.
        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.session.participants')
            ->assertJsonPath('data.session.participants.0.user_id', $coach->id);
    }

    public function test_session_is_cancelled_when_the_last_active_coach_logs_out(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->withToken($coach->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertSame('cancelled', $session->fresh()->status);
    }

    public function test_session_stays_open_when_one_of_two_coaches_logs_out(): void
    {
        [$team, $mainCoach] = $this->makeTeamWithMember('main_coach');
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);
        $session = $this->createSession($team, $mainCoach);
        $this->addParticipant($session, $mainCoach, 'main_coach');
        $this->addParticipant($session, $assistant, 'assistant_coach');

        $this->withToken($assistant->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertSame('queuing', $session->fresh()->status);
    }

    public function test_session_stays_open_when_the_last_coach_logs_out_but_a_player_remains(): void
    {
        // The cancellation rule is "no participants left," not "no coach left" —
        // this is the scenario that tells the two rules apart.
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->withToken($coach->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertSame('queuing', $session->fresh()->status);
    }

    public function test_logging_out_does_not_affect_a_completed_sessions_participants(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'completed');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->withToken($coach->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        $this->assertNull($session->participants()->where('user_id', $coach->id)->first()->left_at);
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_outsider_gets_404_joining_a_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertNotFound();

        $this->assertDatabaseCount('session_participants', 0);
    }

    public function test_joining_a_non_queuing_session_is_forbidden(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertForbidden();

        $this->assertDatabaseCount('session_participants', 0);
    }

    public function test_removing_a_team_member_leaves_their_active_session_participation(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')
            ->deleteJson("/api/teams/members/{$player->id}")
            ->assertOk();

        $this->assertNotNull($session->participants()->where('user_id', $player->id)->first()->left_at);
        $this->assertSame('queuing', $session->fresh()->status);
    }

    public function test_leaving_the_team_leaves_the_users_active_session_participation(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        $this->assertNotNull($session->participants()->where('user_id', $player->id)->first()->left_at);
        $this->assertSame('queuing', $session->fresh()->status);
    }

    public function test_index_returns_the_queuing_session_as_live_and_others_as_past(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $completed = $this->createSession($team, $coach, 'completed', 'Old scrim');
        $cancelled = $this->createSession($team, $coach, 'cancelled', 'Aborted scrim');
        $live = $this->createSession($team, $coach, 'queuing', 'Current scrim');

        $response = $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertOk();

        $response->assertJsonPath('data.live_session.id', $live->id)
            ->assertJsonPath('data.live_session.status', 'queuing')
            ->assertJsonCount(2, 'data.past_sessions');

        $pastIds = collect($response->json('data.past_sessions'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$completed->id, $cancelled->id], $pastIds);
        $this->assertArrayNotHasKey('participants', $response->json('data.live_session'));
    }

    public function test_index_returns_an_in_progress_session_as_live(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'in_progress', 'Current scrim');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertOk()
            ->assertJsonPath('data.live_session.id', $session->id)
            ->assertJsonPath('data.live_session.status', 'in_progress')
            ->assertJsonCount(0, 'data.past_sessions');
    }

    public function test_index_has_no_live_session_when_team_has_no_queuing_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $this->createSession($team, $coach, 'completed', 'Old scrim');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertOk()
            ->assertJsonPath('data.live_session', null)
            ->assertJsonCount(1, 'data.past_sessions');
    }

    public function test_index_search_filters_past_sessions_by_name(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $this->createSession($team, $coach, 'completed', 'Scrim vs Alpha');
        $this->createSession($team, $coach, 'completed', 'Scrim vs Beta');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions?search=Alpha")
            ->assertOk()
            ->assertJsonCount(1, 'data.past_sessions')
            ->assertJsonPath('data.past_sessions.0.session_name', 'Scrim vs Alpha');
    }

    public function test_index_orders_past_sessions_by_date(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $older = $this->createSession($team, $coach, 'completed', 'Older scrim');
        $older->forceFill(['created_at' => now()->subDays(2)])->save();
        $newer = $this->createSession($team, $coach, 'completed', 'Newer scrim');
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions?sort=desc")
            ->assertOk()
            ->assertJsonPath('data.past_sessions.0.id', $newer->id)
            ->assertJsonPath('data.past_sessions.1.id', $older->id);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions?sort=asc")
            ->assertOk()
            ->assertJsonPath('data.past_sessions.0.id', $older->id)
            ->assertJsonPath('data.past_sessions.1.id', $newer->id);
    }

    public function test_index_paginates_past_sessions(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        for ($i = 1; $i <= 3; $i++) {
            $this->createSession($team, $coach, 'completed', "Scrim {$i}");
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions?per_page=2")
            ->assertOk()
            ->assertJsonCount(2, 'data.past_sessions')
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total_pages', 2);
    }

    public function test_outsider_gets_404_listing_a_teams_sessions(): void
    {
        [$team] = $this->makeTeamWithMember('main_coach');
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/teams/{$team->id}/sessions")
            ->assertNotFound();
    }

    public function test_disbanding_the_team_leaves_every_members_active_session_participation(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        $this->assertNotNull($session->participants()->where('user_id', $coach->id)->first()->left_at);
        $this->assertNotNull($session->participants()->where('user_id', $player->id)->first()->left_at);
        $this->assertSame('cancelled', $session->fresh()->status);
    }
}
