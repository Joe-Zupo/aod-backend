<?php

namespace Tests\Feature\Dashboard;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsDashboard;
use Tests\TestCase;

/**
 * GET /api/dashboard/players: the coach roster, the player "you vs team
 * median" view, and the shared insufficient-sessions guard (issue #17).
 */
class PlayersTest extends TestCase
{
    use BuildsDashboard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_coach_sees_a_row_per_active_non_coach_member(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(2);
        [$p0, $p1] = $roster;
        $p1->forceFill(['is_online' => true])->save();

        // p0 plays all three; p1 misses the third.
        $this->analysisReadySession($team, $coach, [$p0, $p1], Carbon::parse('2026-08-11 12:00:00'),
            spec: [
                0 => ['declarative' => 4, 'positive' => 2, 'negative' => 2],
                1 => ['declarative' => 3, 'positive' => 2, 'negative' => 1],
            ]);
        $this->analysisReadySession($team, $coach, [$p0, $p1], Carbon::parse('2026-08-12 12:00:00'),
            spec: [
                0 => ['declarative' => 2, 'positive' => 1, 'negative' => 1],
                1 => ['declarative' => 1],
            ]);
        $this->analysisReadySession($team, $coach, [$p0], Carbon::parse('2026-08-13 12:00:00'),
            spec: [0 => ['declarative' => 2, 'positive' => 2]]);

        $response = $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonCount(2, 'data.players');

        // p0: 8 events over 3 x 5-minute windows = 0.53/min; 8 assessed, 3
        // negative -> 62.5; played all 3.
        $response->assertJsonFragment([
            'user_id' => $p0->id,
            'username' => $p0->username,
            'is_online' => false,
            'comm_frequency' => 0.53,
            'alignment_rate' => 62.5,
            'calls_logged' => 8,
            'sessions_played' => 3,
        ]);

        // p1: 4 events over 2 x 5-minute windows = 0.4/min; 3 assessed, 1
        // negative -> 66.67; played 2.
        $response->assertJsonFragment([
            'user_id' => $p1->id,
            'username' => $p1->username,
            'is_online' => true,
            'comm_frequency' => 0.4,
            'alignment_rate' => 66.67,
            'calls_logged' => 4,
            'sessions_played' => 2,
        ]);
    }

    public function test_a_member_who_played_none_of_the_pool_renders_with_zeros(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(2);
        [$p0, $p1] = $roster;

        foreach (range(1, 3) as $i) {
            $this->analysisReadySession($team, $coach, [$p0],
                Carbon::parse('2026-08-10 12:00:00')->addDays($i),
                spec: [0 => ['declarative' => 2]]);
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonFragment([
                'user_id' => $p1->id,
                'comm_frequency' => 0.0,
                'alignment_rate' => null,
                'calls_logged' => 0,
                'sessions_played' => 0,
            ]);
    }

    public function test_a_team_with_no_players_returns_an_empty_roster(): void
    {
        [$team, $coach] = $this->dashboardTeam(0);

        foreach (range(1, 3) as $i) {
            $this->analysisReadySession($team, $coach, [],
                Carbon::parse('2026-08-10 12:00:00')->addDays($i));
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonPath('data.players', [])
            ->assertJsonPath('data.window.sessions_analyzed', 3)
            ->assertJsonMissingPath('data.message');
    }

    public function test_a_player_sees_their_own_line_against_the_team_median(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(3);
        [$p0] = $roster;

        $this->analysisReadySession($team, $coach, $roster, Carbon::parse('2026-08-11 12:00:00'), spec: [
            0 => ['declarative' => 2, 'negative' => 2],
            1 => ['declarative' => 4, 'negative' => 2, 'positive' => 2],
            2 => ['declarative' => 1, 'negative' => 1],
        ]);
        foreach (['2026-08-12 12:00:00', '2026-08-13 12:00:00'] as $day) {
            $this->analysisReadySession($team, $coach, $roster, Carbon::parse($day), spec: [
                0 => ['declarative' => 2, 'positive' => 2],
                1 => ['declarative' => 4, 'positive' => 2],
                2 => ['declarative' => 1, 'positive' => 1],
            ]);
        }

        // p0: 6 events / 15 min = 0.4; 6 assessed, 2 negative -> 66.67.
        // freq across roster [0.4, 0.8, 0.2] -> median 0.4.
        // calls across roster [6, 12, 3] -> median 6.
        // alignment across roster [66.67, 75.0, 66.67] -> median 66.67.
        $this->actingAs($p0, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonMissingPath('data.players')
            ->assertJsonPath('data.you.user_id', $p0->id)
            ->assertJsonPath('data.you.comm_frequency', 0.4)
            ->assertJsonPath('data.you.alignment_rate', 66.67)
            ->assertJsonPath('data.you.calls_logged', 6)
            ->assertJsonMissingPath('data.you.is_online')
            ->assertJsonMissingPath('data.you.sessions_played')
            ->assertJsonPath('data.team_median.comm_frequency', 0.4)
            ->assertJsonPath('data.team_median.alignment_rate', 66.67)
            ->assertJsonPath('data.team_median.calls_logged', 6);
    }

    public function test_a_short_pool_collapses_the_players_body_to_a_message(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(1);
        $this->analysisReadySession($team, $coach, $roster, Carbon::parse('2026-08-11 12:00:00'));
        $this->analysisReadySession($team, $coach, $roster, Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonPath('data.message', 'Insufficient sessions queried for Player Stats')
            ->assertJsonPath('data.window.sessions_analyzed', 2)
            ->assertJsonMissingPath('data.players');
    }

    public function test_the_short_pool_guard_also_applies_to_a_player_caller(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(1);
        $this->analysisReadySession($team, $coach, $roster, Carbon::parse('2026-08-11 12:00:00'));
        $this->analysisReadySession($team, $coach, $roster, Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs($roster[0], 'sanctum')
            ->getJson('/api/dashboard/players')
            ->assertOk()
            ->assertJsonPath('data.message', 'Insufficient sessions queried for Player Stats')
            ->assertJsonMissingPath('data.you')
            ->assertJsonMissingPath('data.team_median');
    }

    public function test_the_players_pool_honours_the_sessions_param(): void
    {
        [$team, $coach, $roster] = $this->dashboardTeam(1);

        foreach (range(1, 5) as $i) {
            $this->analysisReadySession($team, $coach, $roster,
                Carbon::parse('2026-08-10 12:00:00')->addDays($i),
                spec: [0 => ['declarative' => 2]]);
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/players?sessions=5')
            ->assertOk()
            ->assertJsonPath('data.window.sessions_analyzed', 5)
            ->assertJsonPath('data.players.0.calls_logged', 10);
    }
}
