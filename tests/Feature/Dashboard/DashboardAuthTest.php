<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Who may reach the two dashboard endpoints (issue #17). Both serve any
 * active member; the players endpoint shapes its body by role. A user with
 * no active team gets 404.
 */
class DashboardAuthTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_player_gets_the_same_header_shape_as_a_coach(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $coachBody = $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->json('data');

        $playerBody = $this->actingAs($player, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->json('data');

        // Same blocks, same team-wide numbers; only identity.user differs.
        $this->assertSame(array_keys($coachBody), array_keys($playerBody));
        $this->assertSame($coachBody['kpi'], $playerBody['kpi']);
        $this->assertSame($coachBody['comm_mix'], $playerBody['comm_mix']);
        $this->assertSame($coachBody['identity']['team'], $playerBody['identity']['team']);
        $this->assertSame($player->id, $playerBody['identity']['user']['id']);
    }

    public function test_a_user_with_no_active_team_gets_404_from_both_endpoints(): void
    {
        $stray = User::factory()->create();
        $stray->assignRole('Player');

        $this->actingAs($stray, 'sanctum')->getJson('/api/dashboard/header')->assertNotFound();
        $this->actingAs($stray, 'sanctum')->getJson('/api/dashboard/players')->assertNotFound();
    }

    public function test_a_player_can_reach_the_players_endpoint(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($player, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk();
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $this->getJson('/api/dashboard/header')->assertUnauthorized();
        $this->getJson('/api/dashboard/players')->assertUnauthorized();
    }
}
