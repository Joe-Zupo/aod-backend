<?php

namespace Tests\Unit\Events;

use App\Events\SessionParticipantLeft;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionParticipantLeftTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_on_the_participants_session_channel(): void
    {
        $participant = new SessionParticipant(['session_id' => 42]);

        $channels = (new SessionParticipantLeft($participant))->broadcastOn();

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
            'participant_role' => 'player',
            'joined_at' => '2026-08-26 12:00:00',
            'left_at' => '2026-08-26 13:30:00',
        ]);
        $participant->setRelation('user', $user);

        $payload = (new SessionParticipantLeft($participant))->broadcastWith();

        $this->assertSame([
            'session_id' => 42,
            'user_id' => $user->id,
            'username' => 'trevor',
            'participant_role' => 'player',
            'joined_at' => '2026-08-26T12:00:00.000000Z',
            'left_at' => '2026-08-26T13:30:00.000000Z',
        ], json_decode(json_encode($payload), true));
    }
}
