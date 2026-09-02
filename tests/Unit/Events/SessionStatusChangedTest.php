<?php

namespace Tests\Unit\Events;

use App\Events\SessionStatusChanged;
use App\Models\Session;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class SessionStatusChangedTest extends TestCase
{
    public function test_it_broadcasts_on_the_sessions_channel(): void
    {
        $session = new Session(['status' => Session::STATUS_IN_PROGRESS]);
        $session->id = 42;

        $channels = (new SessionStatusChanged($session))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-session.42', $channels[0]->name);
    }

    public function test_it_broadcasts_the_sessions_status_shape(): void
    {
        $session = new Session([
            'team_id' => 7,
            'status' => Session::STATUS_PROCESSING,
        ]);
        $session->id = 42;

        $payload = (new SessionStatusChanged($session))->broadcastWith();

        $this->assertSame([
            'session_id' => 42,
            'team_id' => 7,
            'status' => Session::STATUS_PROCESSING,
        ], json_decode(json_encode($payload), true));
    }
}
