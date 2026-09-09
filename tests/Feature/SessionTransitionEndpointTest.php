<?php

namespace Tests\Feature;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * POST /sessions/{session}/transitions is the one endpoint for every
 * coach-driven, bodiless state move (cancel, re-analyze, analysis-ready,
 * reopen-review). The individual guards and side effects are covered by
 * SessionControlTest / SessionReanalyzeTest / AnalysisReadyTransitionTest; this
 * covers the `to` vocabulary and the shared policy (ADR 0010).
 */
class SessionTransitionEndpointTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_an_unknown_target_status_is_a_422(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'ready_for_review'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['to']]]);
    }

    public function test_to_in_progress_points_at_the_start_endpoint(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use POST /sessions/{session}/start to begin recording.');
    }

    public function test_a_player_cannot_transition_a_session(): void
    {
        [, , $session] = $this->timelineReadySession();
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertForbidden();
    }

    public function test_one_endpoint_drives_reopen_from_analysis_ready(): void
    {
        [, $coach, $session] = $this->timelineReadySession();
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'timeline_ready'])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_TIMELINE_READY);
    }
}
