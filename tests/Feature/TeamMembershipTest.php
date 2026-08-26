<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamMembershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function makeTeamWithMainCoach(): array
    {
        $coach = User::factory()->create();
        $coach->assignRole('Coach');

        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $team->members()->attach($coach, [
            'member_role' => 'main_coach',
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $coach->id,
            'decided_at' => now(),
        ]);

        return [$team, $coach];
    }

    public function test_teamless_user_can_request_to_join_and_main_coach_can_approve(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'tm-aodteam1'])
            ->assertOk();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $player->id,
            'member_role' => 'player',
            'status' => 'pending',
        ]);

        $this->actingAs($coach, 'sanctum')->getJson('/api/teams/join-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data.requests')
            ->assertJsonPath('data.requests.0.id', $player->id);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/teams/join-requests/{$player->id}", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $player->id,
            'status' => 'active',
        ]);

        $this->actingAs($player, 'sanctum')->getJson('/api/me/teams')
            ->assertOk()
            ->assertJsonCount(1, 'data.teams');
    }

    public function test_rejected_request_can_be_resubmitted(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'TM-AODTEAM1']);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/teams/join-requests/{$player->id}", ['action' => 'reject'])
            ->assertOk();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $player->id,
            'status' => 'rejected',
        ]);

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'TM-AODTEAM1'])
            ->assertOk();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $player->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('team_members', 2);
    }

    public function test_player_cap_of_five_is_enforced_on_approval(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        for ($i = 0; $i < 5; $i++) {
            $existing = User::factory()->create();
            $existing->assignRole('Player');
            $team->members()->attach($existing, [
                'member_role' => 'player',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $sixth = User::factory()->create();
        $sixth->assignRole('Player');
        $this->actingAs($sixth, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'TM-AODTEAM1']);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/teams/join-requests/{$sixth->id}", ['action' => 'approve'])
            ->assertStatus(422);

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $sixth->id,
            'status' => 'pending',
        ]);
    }

    public function test_assistant_coach_cannot_manage_join_requests_or_remove_members(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $assistant = User::factory()->create();
        $assistant->assignRole('Coach');
        $team->members()->attach($assistant, ['member_role' => 'assistant_coach', 'status' => 'active', 'joined_at' => now()]);
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($assistant, 'sanctum')->getJson('/api/teams/join-requests')
            ->assertForbidden();

        $this->actingAs($assistant, 'sanctum')->deleteJson("/api/teams/members/{$player->id}")
            ->assertForbidden();
    }

    public function test_player_cannot_manage_join_requests_or_remove_members(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);
        $otherPlayer = User::factory()->create();
        $otherPlayer->assignRole('Player');
        $team->members()->attach($otherPlayer, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')->getJson('/api/teams/join-requests')
            ->assertForbidden();

        $this->actingAs($player, 'sanctum')->deleteJson("/api/teams/members/{$otherPlayer->id}")
            ->assertForbidden();
    }

    public function test_main_coach_can_remove_a_player_returning_them_to_teamless_state(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($coach, 'sanctum')->deleteJson("/api/teams/members/{$player->id}")
            ->assertOk();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $player->id,
            'status' => 'removed',
        ]);

        $this->actingAs($player, 'sanctum')->getJson('/api/me/teams')
            ->assertOk()
            ->assertJsonCount(0, 'data.teams');
    }

    public function test_main_coach_cannot_be_removed_via_the_remove_member_endpoint(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')->deleteJson("/api/teams/members/{$coach->id}")
            ->assertStatus(422);
    }

    public function test_player_leaving_only_affects_their_own_membership(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/leave')
            ->assertOk();

        $this->assertDatabaseHas('team_members', ['user_id' => $player->id, 'status' => 'removed']);
        $this->assertDatabaseHas('team_members', ['user_id' => $coach->id, 'status' => 'active']);
        $this->assertNull($team->fresh()->disbanded_at);
    }

    public function test_main_coach_leaving_disbands_the_team(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($coach, 'sanctum')->postJson('/api/teams/leave')
            ->assertOk();

        $this->assertNotNull($team->fresh()->disbanded_at);
        $this->assertDatabaseHas('team_members', ['user_id' => $coach->id, 'status' => 'removed']);
        $this->assertDatabaseHas('team_members', ['user_id' => $player->id, 'status' => 'removed']);
    }

    public function test_user_cannot_have_more_than_one_active_or_pending_membership(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $otherTeam = Team::create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);

        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'TM-SECOND01'])
            ->assertStatus(422);
    }

    public function test_cannot_join_a_disbanded_team(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $team->disbanded_at = now();
        $team->save();

        $player = User::factory()->create();
        $player->assignRole('Player');

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/join', ['team_code' => 'TM-AODTEAM1'])
            ->assertUnprocessable();
    }
}
