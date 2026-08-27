<?php

namespace Tests\Unit\Events;

use App\Events\UserWentOnline;
use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserWentOnlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_on_the_teams_channel(): void
    {
        $user = new User(['username' => 'trevor']);
        $team = new Team;
        $team->id = 42;

        $channels = (new UserWentOnline($user, $team))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-team.42', $channels[0]->name);
    }

    public function test_it_broadcasts_the_users_public_shape(): void
    {
        $user = User::factory()->create(['username' => 'trevor', 'is_online' => true]);
        $team = new Team;
        $team->id = 42;

        $payload = (new UserWentOnline($user, $team))->broadcastWith();

        $this->assertSame([
            'user_id' => $user->id,
            'username' => 'trevor',
            'is_online' => true,
        ], json_decode(json_encode($payload), true));
    }
}
