<?php

namespace Tests\Unit\Models;

use App\Events\SessionParticipantJoined;
use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SessionBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_joining_a_session_for_the_first_time_dispatches_session_participant_joined(): void
    {
        Event::fake([SessionParticipantJoined::class]);

        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $session = Session::factory()->for($team)->create();

        $participant = $session->joinOrRejoin($user);

        Event::assertDispatched(SessionParticipantJoined::class, fn ($event) => $event->participant->is($participant));
    }

    public function test_rejoining_after_leaving_dispatches_session_participant_joined(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($user);
        $participant->leave();

        Event::fake([SessionParticipantJoined::class]);

        $rejoined = $session->joinOrRejoin($user);

        Event::assertDispatched(SessionParticipantJoined::class, fn ($event) => $event->participant->is($rejoined));
    }

    public function test_joining_an_already_active_participant_dispatches_nothing(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $session = Session::factory()->for($team)->create();
        $session->joinOrRejoin($user);

        Event::fake([SessionParticipantJoined::class]);

        $session->joinOrRejoin($user);

        Event::assertNotDispatched(SessionParticipantJoined::class);
    }
}
