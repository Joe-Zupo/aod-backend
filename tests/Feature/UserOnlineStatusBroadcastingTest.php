<?php

namespace Tests\Feature;

use App\Events\UserWentOffline;
use App\Events\UserWentOnline;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserOnlineStatusBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Event::fake([UserWentOnline::class, UserWentOffline::class]);
    }

    public function test_login_dispatches_user_went_online(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['password' => 'password123']);
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();

        Event::assertDispatched(
            UserWentOnline::class,
            fn ($event) => $event->user->is($user) && $event->team->is($team)
        );
    }

    public function test_registering_and_creating_a_team_dispatches_user_went_online(): void
    {
        $this->postJson('/api/register', [
            'username' => 'coach-one',
            'email' => 'coach@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'Coach',
            'team_action' => 'create',
            'team_name' => 'Aces of Dawn',
        ])->assertCreated();

        $user = User::where('email', 'coach@example.com')->first();
        $team = Team::where('team_name', 'Aces of Dawn')->first();

        Event::assertDispatched(
            UserWentOnline::class,
            fn ($event) => $event->user->is($user) && $event->team->is($team)
        );
    }

    public function test_logout_dispatches_user_went_offline(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['is_online' => true]);
        $team->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->withToken($user->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        Event::assertDispatched(
            UserWentOffline::class,
            fn ($event) => $event->user->is($user) && $event->team->is($team)
        );
    }

    public function test_login_response_reflects_is_online(): void
    {
        $user = User::factory()->create(['password' => 'password123', 'is_online' => false]);

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('data.user.is_online', true);
    }

    public function test_team_roster_reflects_members_is_online(): void
    {
        $team = Team::factory()->create();
        $onlineCoach = User::factory()->create(['is_online' => true]);
        $offlinePlayer = User::factory()->create(['is_online' => false]);
        $team->members()->attach($onlineCoach, ['member_role' => 'main_coach', 'status' => 'active', 'joined_at' => now()]);
        $team->members()->attach($offlinePlayer, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $response = $this->actingAs($onlineCoach, 'sanctum')->getJson('/api/teams')->assertOk();

        $members = collect($response->json('data.team.members'))->keyBy('id');
        $this->assertTrue($members[$onlineCoach->id]['is_online']);
        $this->assertFalse($members[$offlinePlayer->id]['is_online']);
    }
}
