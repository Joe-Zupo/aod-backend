<?php

namespace Tests\Unit\Models;

use App\Events\UserWentOffline;
use App\Events\UserWentOnline;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class UserOnlineStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_going_online_sets_is_online_and_dispatches_user_went_online(): void
    {
        Event::fake([UserWentOnline::class]);

        $team = Team::factory()->create();
        $user = User::factory()->create(['is_online' => false]);
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $user->goOnline();

        $this->assertTrue($user->fresh()->is_online);
        Event::assertDispatched(
            UserWentOnline::class,
            fn ($event) => $event->user->is($user) && $event->team->is($team)
        );
    }

    public function test_going_online_with_no_active_team_sets_is_online_but_dispatches_nothing(): void
    {
        Event::fake([UserWentOnline::class]);

        $user = User::factory()->create(['is_online' => false]);

        $user->goOnline();

        $this->assertTrue($user->fresh()->is_online);
        Event::assertNotDispatched(UserWentOnline::class);
    }

    public function test_going_offline_sets_is_online_and_dispatches_user_went_offline(): void
    {
        Event::fake([UserWentOffline::class]);

        $team = Team::factory()->create();
        $user = User::factory()->create(['is_online' => true]);
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $user->goOffline();

        $this->assertFalse($user->fresh()->is_online);
        Event::assertDispatched(
            UserWentOffline::class,
            fn ($event) => $event->user->is($user) && $event->team->is($team)
        );
    }

    public function test_going_offline_with_no_active_team_sets_is_online_but_dispatches_nothing(): void
    {
        Event::fake([UserWentOffline::class]);

        $user = User::factory()->create(['is_online' => true]);

        $user->goOffline();

        $this->assertFalse($user->fresh()->is_online);
        Event::assertNotDispatched(UserWentOffline::class);
    }
}
