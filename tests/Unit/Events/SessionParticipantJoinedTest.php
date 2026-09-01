<?php

namespace Tests\Unit\Events;

use App\Events\SessionParticipantJoined;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionParticipantJoinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_on_the_participants_session_channel(): void
    {
        $participant = new SessionParticipant(['session_id' => 42]);

        $channels = (new SessionParticipantJoined($participant))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-session.42', $channels[0]->name);
    }

    public function test_it_broadcasts_the_participants_public_shape(): void
    {
        $user = User::factory()->create(['username' => 'trevor']);
        $participant = SessionParticipant::factory()->make([
            'session_id' => 42,
            'user_id' => $user->id,
            'participant_role' => 'main_coach',
            'participant_status' => 'ready',
            'joined_at' => '2026-08-26 12:00:00',
        ]);
        $participant->setRelation('user', $user);

        $payload = (new SessionParticipantJoined($participant))->broadcastWith();

        // Compared post-JSON-encoding since that's the actual wire contract:
        // broadcastWith()'s return value is what Laravel json_encodes and
        // sends to Pusher, regardless of whether it holds raw Carbon
        // instances or already-stringified values internally.
        $this->assertSame([
            'session_id' => 42,
            'user_id' => $user->id,
            'username' => 'trevor',
            'participant_role' => 'main_coach',
            'participant_status' => 'ready',
            'joined_at' => '2026-08-26T12:00:00.000000Z',
            'left_at' => null,
        ], json_decode(json_encode($payload), true));
    }
}
