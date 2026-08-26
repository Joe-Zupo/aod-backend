<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_user_only_receives_active_team_memberships(): void
    {
        $user = User::factory()->create();
        $activeTeam = Team::create(['team_name' => 'Active', 'team_code' => 'ACTIVE01']);
        $formerTeam = Team::create(['team_name' => 'Former', 'team_code' => 'FORMER01']);

        $activeTeam->members()->attach($user, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $formerTeam->members()->attach($user, ['member_role' => 'player', 'status' => 'removed', 'joined_at' => now(), 'left_at' => now()]);

        $this->actingAs($user, 'sanctum')->getJson('/api/me/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data.teams')
            ->assertJsonPath('data.teams.0.id', $activeTeam->id)
            ->assertJsonPath('data.teams.0.team_code', 'ACTIVE01');
    }

    public function test_show_team_includes_active_members_and_team_code_for_any_active_role(): void
    {
        $coach = User::factory()->create();
        $player = User::factory()->create();
        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'VISIBLE1']);
        $team->members()->attach($coach, ['member_role' => 'main_coach', 'status' => 'active', 'joined_at' => now()]);
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        // No ?team= passed: resolves automatically to the caller's own active team.
        $response = $this->actingAs($player, 'sanctum')->getJson('/api/teams')
            ->assertOk()
            ->assertJsonPath('data.team.team_code', 'VISIBLE1')
            ->assertJsonCount(2, 'data.team.members');

        $memberRoles = collect($response->json('data.team.members'))->pluck('member_role')->sort()->values()->all();
        $this->assertSame(['main_coach', 'player'], $memberRoles);
    }

    public function test_teamless_user_gets_not_found_with_no_team_to_default_to(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/teams')
            ->assertNotFound();
    }

    public function test_non_member_cannot_discover_a_team_via_the_team_query_override(): void
    {
        $user = User::factory()->create();
        $team = Team::create(['team_name' => 'Private Team', 'team_code' => 'PRIVATE1']);

        $this->actingAs($user, 'sanctum')->getJson("/api/teams?team={$team->id}")
            ->assertNotFound();
    }

    public function test_player_cannot_update_team_but_active_coach_can_without_changing_code(): void
    {
        $coach = User::factory()->create();
        $player = User::factory()->create();
        $team = Team::create(['team_name' => 'Original', 'team_code' => 'IMMUTABL']);
        $team->members()->attach($coach, ['member_role' => 'main_coach', 'status' => 'active', 'joined_at' => now()]);
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')->putJson('/api/teams', ['team_name' => 'Changed'])
            ->assertForbidden();

        $this->actingAs($coach, 'sanctum')->putJson('/api/teams', [
            'team_name' => 'Changed',
            'description' => 'A private Valorant team.',
        ])->assertOk()
            ->assertJsonPath('data.team.team_name', 'Changed')
            ->assertJsonPath('data.team.team_code', 'IMMUTABL');

        $this->actingAs($coach, 'sanctum')->putJson('/api/teams', ['team_code' => 'REPLACED'])
            ->assertUnprocessable();

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'team_code' => 'IMMUTABL']);
    }

    public function test_user_can_update_only_own_profile_and_cannot_submit_a_role(): void
    {
        $user = User::factory()->create(['username' => 'before']);

        $this->actingAs($user, 'sanctum')->putJson('/api/me', ['username' => 'after'])
            ->assertOk()
            ->assertJsonPath('data.user.username', 'after');

        $this->actingAs($user, 'sanctum')->putJson('/api/me', ['role' => 'Coach'])
            ->assertUnprocessable();
    }
}
