<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * While a session is `timeline_ready` the timeline is coach-only; a player
 * reaches it once it is `analysis_ready` (docs/adr/0010-timeline-management.md).
 */
class TimelineVisibilityTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_player_is_forbidden_from_the_timeline_while_under_review(): void
    {
        [, , $session] = $this->timelineReadySession(comm: 1);
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}/timeline")->assertForbidden();
        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}/timeline-summary")->assertForbidden();
    }

    public function test_a_coach_reaches_the_timeline_while_under_review(): void
    {
        [, $coach, $session] = $this->timelineReadySession(comm: 1);

        $this->actingAs($coach, 'sanctum')->getJson("/api/sessions/{$session->id}/timeline")->assertOk();
    }

    public function test_a_player_reaches_the_timeline_once_analysis_ready(): void
    {
        [, , $session] = $this->timelineReadySession(comm: 1);
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}/timeline")->assertOk();
        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}/timeline-summary")->assertOk();
    }

    public function test_captions_and_show_stay_open_to_a_player_while_under_review(): void
    {
        [, , $session] = $this->timelineReadySession(comm: 1);
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}")->assertOk();
        $this->actingAs($player, 'sanctum')->getJson("/api/sessions/{$session->id}/captions")->assertOk();
    }
}
