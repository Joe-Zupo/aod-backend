<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamKeyword;
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

    public function test_creating_a_team_creates_its_team_settings_with_default_threshold(): void
    {
        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'setting_name' => 'dead_air_threshold',
            'setting_parameter' => 5000,
        ]);
    }

    public function test_creating_a_team_creates_its_comm_event_padding_setting(): void
    {
        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'setting_name' => 'comm_event_padding',
            'setting_parameter' => 2000,
        ]);
    }

    public function test_creating_a_team_creates_its_game_alignment_window_setting(): void
    {
        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'setting_name' => 'game_alignment_window',
            'setting_parameter' => 5000,
        ]);
    }

    public function test_main_coach_can_view_team_settings(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $response = $this->actingAs($coach, 'sanctum')->getJson('/api/teams/settings')
            ->assertOk()
            ->assertJsonPath('data.settings.team_id', $team->id)
            ->assertJsonPath('data.settings.dead_air_threshold_ms', 5000)
            ->assertJsonPath('data.settings.comm_event_padding_ms', 2000)
            ->assertJsonPath('data.settings.game_alignment_window_ms', 5000)
            ->assertJsonCount(count(TeamKeyword::DEFAULT_INFORMATIVE_KEYWORDS), 'data.settings.informative_keywords')
            ->assertJsonCount(count(TeamKeyword::DEFAULT_DECLARATIVE_KEYWORDS), 'data.settings.declarative_keywords');

        $this->assertContains('planted', $response->json('data.settings.informative_keywords'));
        $this->assertContains('flashing', $response->json('data.settings.declarative_keywords'));
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
            'setting_name' => 'dead_air_threshold',
            'setting_parameter' => 8000,
        ]);
    }

    public function test_main_coach_can_update_comm_event_padding(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 5000, 'comm_event_padding_ms' => 3500])
            ->assertOk()
            ->assertJsonPath('data.settings.comm_event_padding_ms', 3500);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'setting_name' => 'comm_event_padding',
            'setting_parameter' => 3500,
        ]);
    }

    public function test_update_without_comm_event_padding_leaves_it_untouched(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 7000])
            ->assertOk()
            ->assertJsonPath('data.settings.comm_event_padding_ms', 2000);
    }

    public function test_update_rejects_a_non_positive_comm_event_padding(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 5000, 'comm_event_padding_ms' => 0])
            ->assertStatus(422);
    }

    public function test_main_coach_can_update_game_alignment_window(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 5000, 'game_alignment_window_ms' => 8000])
            ->assertOk()
            ->assertJsonPath('data.settings.game_alignment_window_ms', 8000);

        $this->assertDatabaseHas('team_settings', [
            'team_id' => $team->id,
            'setting_name' => 'game_alignment_window',
            'setting_parameter' => 8000,
        ]);
    }

    public function test_update_without_game_alignment_window_leaves_it_untouched(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 7000])
            ->assertOk()
            ->assertJsonPath('data.settings.game_alignment_window_ms', 5000);
    }

    public function test_update_rejects_a_non_positive_game_alignment_window(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 5000, 'game_alignment_window_ms' => 0])
            ->assertStatus(422);
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
            'setting_name' => 'dead_air_threshold',
            'setting_parameter' => 5000,
        ]);
    }

    public function test_player_gets_forbidden_not_a_validation_error_for_an_invalid_update_payload(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();
        $player = User::factory()->create();
        $player->assignRole('Player');
        $team->members()->attach($player, ['member_role' => 'player', 'status' => 'active', 'joined_at' => now()]);

        $this->actingAs($player, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 'not-a-number'])
            ->assertForbidden();
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
        $otherTeam = Team::create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
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
            'setting_name' => 'dead_air_threshold',
            'setting_parameter' => 5000,
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
            'setting_name' => 'dead_air_threshold',
            'setting_parameter' => 5000,
        ]);
    }

    public function test_update_rejects_a_threshold_beyond_the_column_bound(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 2147483648])
            ->assertStatus(422);
    }

    public function test_update_rejects_a_non_positive_threshold(): void
    {
        [$team, $coach] = $this->makeTeamWithMainCoach();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings', ['dead_air_threshold_ms' => 0])
            ->assertStatus(422);
    }
}
