<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamSettingsTest extends TestCase
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

        $team = new Team(['team_name' => 'Aces of Dawn']);
        $team->team_code = 'TM-AODTEAM1';
        $team->save();

        $team->members()->attach($coach, [
            'member_role' => 'main_coach',
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $coach->id,
            'decided_at' => now(),
        ]);

        return [$team, $coach];
    }

    public function test_creating_a_team_creates_its_team_settings_with_default_threshold(): void
    {
        $team = new Team(['team_name' => 'Aces of Dawn']);
        $team->team_code = 'TM-AODTEAM1';
        $team->save();

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'dead_air_threshold_ms' => 5000,
        ]);
    }

    public function test_main_coach_can_view_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')->getJson('/api/teams/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.team_id', $team->id)
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 5000);
    }

    public function test_assistant_coach_can_view_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $assistant = User::factory()->create();
        $assistant->assignRole('Coach');
        $team->members()->attach($assistant, ['member_role' => 'assistant_coach', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($assistant, 'sanctum')->getJson('/api/teams/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 5000);
    }

    public function test_player_cannot_view_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')->getJson('/api/teams/settings')
            ->assertForbidden();
    }

    public function test_outsider_cannot_view_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')->getJson('/api/teams/settings?team='.$team->id)
            ->assertNotFound();
    }

    public function test_main_coach_can_update_dead_air_threshold(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 8000])
            ->assertOk()
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 8000);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'dead_air_threshold_ms' => 8000,
        ]);
    }

    public function test_assistant_coach_can_update_dead_air_threshold(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $assistant = User::factory()->create();
        $assistant->assignRole('Coach');
        $team->members()->attach($assistant, ['member_role' => 'assistant_coach', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($assistant, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 3000])
            ->assertOk()
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 3000);
    }

    public function test_player_cannot_update_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 3000])
            ->assertForbidden();

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'dead_air_threshold_ms' => 5000,
        ]);
    }

    public function test_outsider_cannot_update_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')
            ->putJson('/api/teams/settings?team='.$team->id, ['dead_air_threshold_ms' => 3000])
            ->assertNotFound();
    }

    public function test_coach_cannot_view_or_update_another_teams_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = new Team(['team_name' => 'Second Team']);
        $otherTeam->team_code = 'TM-SECOND01';
        $otherTeam->save();
        $otherTeam->members()->attach($otherCoach, [
            'member_role' => 'main_coach',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($otherCoach, 'sanctum')->getJson('/api/teams/settings?team='.$team->id)
            ->assertNotFound();

        $this->actingAs($otherCoach, 'sanctum')
            ->putJson('/api/teams/settings?team='.$team->id, ['dead_air_threshold_ms' => 3000])
            ->assertNotFound();

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'dead_air_threshold_ms' => 5000,
        ]);
    }

    public function test_outsider_request_does_not_create_a_settings_row_for_the_target_team(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $team->settings()->delete();
        $outsider = User::factory()->create();
        $outsider->assignRole('Player');

        $this->actingAs($outsider, 'sanctum')->getJson('/api/teams/settings?team='.$team->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('team_settings', ['team_id' => $team->id]);
    }

    public function test_viewing_settings_for_a_team_missing_its_settings_row_self_heals(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $team->settings()->delete();
        $this->assertDatabaseMissing('team_settings', ['team_id' => $team->id]);

        $this->actingAs($coach, 'sanctum')->getJson('/api/teams/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 5000);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'dead_air_threshold_ms' => 5000,
        ]);
    }

    public function test_update_rejects_a_threshold_beyond_the_column_bound(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 4294967296])
            ->assertStatus(422);
    }
}
