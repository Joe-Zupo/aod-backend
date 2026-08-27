<?php

namespace Tests\Unit\Models;

use App\Events\SessionParticipantLeft;
use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SessionParticipantBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    public function test_leaving_a_session_dispatches_session_participant_left(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($user);

        Event::fake([SessionParticipantLeft::class]);

        $participant->leave();

        Event::assertDispatched(SessionParticipantLeft::class, fn ($event) => $event->participant->is($participant));
    }
}
