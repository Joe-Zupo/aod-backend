<?php

namespace Tests\Feature;

use App\Events\SessionParticipantJoined;
use App\Events\SessionParticipantLeft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

class SessionBroadcastingTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);
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

    public function test_cancelling_a_session_dispatches_session_participant_left_for_each_active_participant(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cancel")
            ->assertOk();

        Event::assertDispatched(SessionParticipantLeft::class, 2);
    }

    public function test_cancelling_a_session_does_not_dispatch_for_a_participant_who_already_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player)->leave();

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cancel")
            ->assertOk();

        Event::assertDispatched(SessionParticipantLeft::class, 1);
        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
    }
}
