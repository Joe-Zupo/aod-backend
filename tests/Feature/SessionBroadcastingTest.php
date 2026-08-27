<?php

namespace Tests\Feature;

use App\Events\SessionParticipantJoined;
use App\Events\SessionParticipantLeft;
use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SessionBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);
    }

    private function makeTeamWithMember(string $memberRole, string $spatieRole = 'Coach'): array
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        $team = Team::factory()->create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $team->members()->attach($user, [
            'member_role' => $memberRole,
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);

        return [$team, $user];
    }

    private function makeAndAttachMember(Team $team, string $memberRole, string $spatieRole, User $decidedBy): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        $team->members()->attach($user, [
            'member_role' => $memberRole,
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
        ]);

        return $user;
    }

    private function createSession(Team $team, User $creator, string $status = 'queuing'): Session
    {
        return Session::factory()->for($team)->create([
            'created_by' => $creator->id,
            'status' => $status,
        ]);
    }

    public function test_explicit_join_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_rejoining_after_leaving_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player)->leave();

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_creating_a_session_auto_joins_the_creator_and_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim vs Team B'])
            ->assertCreated();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
    }

    public function test_logout_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->withToken($player->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_member_removal_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->deleteJson("/api/teams/members/{$player->id}")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_self_leave_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_disbanding_the_team_dispatches_session_participant_left_for_every_member(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }
}
