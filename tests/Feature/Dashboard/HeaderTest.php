<?php

namespace Tests\Feature\Dashboard;

use App\Models\Session;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsDashboard;
use Tests\TestCase;

/**
 * GET /api/dashboard/header: the identity block, the Communication KPI and
 * comm-mix cards over the N most recent analysis-ready sessions, and the
 * insufficient-sessions guard (issue #17).
 */
class HeaderTest extends TestCase
{
    use BuildsDashboard, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_the_identity_block_carries_the_team_the_user_and_the_analysis_ready_count(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $this->createSession($team, $coach, Session::STATUS_ANALYSIS_READY);
        $this->createSession($team, $coach, Session::STATUS_ANALYSIS_READY);
        $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('data.identity.team.id', $team->id)
            ->assertJsonPath('data.identity.team.team_name', $team->team_name)
            ->assertJsonPath('data.identity.user.id', $coach->id)
            ->assertJsonPath('data.identity.analysis_ready_count', 2);
    }

    public function test_the_window_block_spans_the_n_most_recent_analysis_ready_sessions(): void
    {
        [$team, $coach, $players] = $this->dashboardTeam(1);

        $sessions = collect(range(1, 5))->map(fn ($i) => $this->analysisReadySession(
            $team,
            $coach,
            $players,
            Carbon::parse('2026-08-10 12:00:00')->addDays($i),
        ));

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('data.window.sessions_requested', 3)
            ->assertJsonPath('data.window.sessions_analyzed', 3)
            ->assertJsonPath('data.window.from', $sessions[2]->fresh()->analysis_ready_at->toJSON())
            ->assertJsonPath('data.window.to', $sessions[4]->fresh()->analysis_ready_at->toJSON());
    }

    public function test_the_kpi_block_pools_the_communication_numbers_over_the_window(): void
    {
        [$team, $coach, $players] = $this->dashboardTeam(1);

        // 5 + 4 + 3 = 12 events over 3 x 5-minute windows = 0.8 per minute.
        // alignment: 9 assessed, 3 possibly_negative -> (9 - 3) / 9 * 100 = 66.67.
        // absence: (2 + 1 + 0) periods x 12000 ms = 36000 ms.
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-11 12:00:00'),
            spec: [0 => ['informative' => 3, 'declarative' => 2, 'positive' => 3, 'negative' => 2]],
            deadAir: 2);
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-12 12:00:00'),
            spec: [0 => ['declarative' => 4, 'negative' => 1]],
            deadAir: 1);
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-13 12:00:00'),
            spec: [0 => ['compound' => 3, 'positive' => 1, 'neutral' => 2]]);

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('data.kpi.calls_classified', 12)
            ->assertJsonPath('data.kpi.comm_frequency', 0.8)
            ->assertJsonPath('data.kpi.alignment_rate', 66.67)
            ->assertJsonPath('data.kpi.absence_ms', 36000);
    }

    public function test_a_short_pool_collapses_the_kpi_and_comm_mix_blocks_to_a_message(): void
    {
        [$team, $coach, $players] = $this->dashboardTeam(1);
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-11 12:00:00'));
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-12 12:00:00'));

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('data.identity.analysis_ready_count', 2)
            ->assertJsonPath('data.window.sessions_analyzed', 2)
            ->assertJsonPath('data.kpi.message', 'Insufficient sessions queried for KPI of Communication')
            ->assertJsonPath('data.comm_mix.message', 'Insufficient sessions queried for Communication Mix')
            ->assertJsonMissingPath('data.kpi.comm_frequency');
    }

    public function test_the_comm_mix_block_pools_counts_redundancy_and_absence(): void
    {
        [$team, $coach, $players] = $this->dashboardTeam(1);

        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-11 12:00:00'),
            spec: [0 => ['informative' => 2, 'declarative' => 1, 'compound' => 1, 'redundant' => 2]],
            deadAir: 1);
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-12 12:00:00'),
            spec: [0 => ['informative' => 1, 'declarative' => 3, 'redundant' => 1]],
            deadAir: 2);
        $this->analysisReadySession($team, $coach, $players, Carbon::parse('2026-08-13 12:00:00'),
            spec: [0 => ['compound' => 2]]);

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertOk()
            ->assertJsonPath('data.comm_mix.informative', 3)
            ->assertJsonPath('data.comm_mix.declarative', 4)
            ->assertJsonPath('data.comm_mix.compound', 3)
            ->assertJsonPath('data.comm_mix.redundant', 3)
            ->assertJsonPath('data.comm_mix.absence', 3)
            ->assertJsonPath('data.comm_mix.calls_classified', 10);
    }

    public function test_the_sessions_param_widens_the_pool(): void
    {
        [$team, $coach, $players] = $this->dashboardTeam(1);

        foreach (range(1, 5) as $i) {
            $this->analysisReadySession($team, $coach, $players,
                Carbon::parse('2026-08-10 12:00:00')->addDays($i),
                spec: [0 => ['declarative' => 2]]);
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header')
            ->assertJsonPath('data.window.sessions_requested', 3)
            ->assertJsonPath('data.window.sessions_analyzed', 3)
            ->assertJsonPath('data.kpi.calls_classified', 6);

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/dashboard/header?sessions=5')
            ->assertJsonPath('data.window.sessions_requested', 5)
            ->assertJsonPath('data.window.sessions_analyzed', 5)
            ->assertJsonPath('data.kpi.calls_classified', 10);
    }

    public function test_the_sessions_param_is_bounded(): void
    {
        [, $coach] = $this->dashboardTeam(0);

        foreach (['0', '51', 'abc'] as $bad) {
            $this->actingAs($coach, 'sanctum')
                ->getJson("/api/dashboard/header?sessions={$bad}")
                ->assertStatus(422);
        }
    }
}
