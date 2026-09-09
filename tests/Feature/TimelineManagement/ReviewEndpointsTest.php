<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * The review endpoints: one timestamp, un-review, and review-all
 * (docs/adr/0010-timeline-management.md).
 */
class ReviewEndpointsTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_coach_reviews_and_un_reviews_one_timestamp(): void
    {
        [, $coach, $session] = $this->timelineReadySession(game: 1);
        $event = GameEvent::sole();

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}/review", ['reviewed' => true])
            ->assertOk()
            ->assertJsonPath('data.reviewed', 1)
            ->assertJsonPath('data.total', 1);

        $event->refresh();
        $this->assertNotNull($event->reviewed_at);
        $this->assertSame($coach->id, $event->reviewed_by);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}/review", ['reviewed' => false])
            ->assertOk()
            ->assertJsonPath('data.reviewed', 0);

        $this->assertNull($event->fresh()->reviewed_at);
    }

    public function test_review_all_sweeps_every_unreviewed_timestamp(): void
    {
        [, $coach, $session] = $this->timelineReadySession(comm: 2, game: 2, deadAir: 1);

        // Pre-review one so the sweep must leave it alone.
        $already = GameEvent::first();
        $already->update(['reviewed_at' => now()->subDay(), 'reviewed_by' => $coach->id]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps/review-all")
            ->assertOk()
            ->assertJsonPath('data.reviewed', 5)
            ->assertJsonPath('data.total', 5);

        $this->assertTrue(
            $already->fresh()->reviewed_at->isYesterday(),
            'an already-reviewed row keeps its original timestamp',
        );
        $this->assertSame(0, CommEvent::unreviewed()->count());
        $this->assertSame(0, DeadAirPeriod::unreviewed()->count());
    }

    public function test_a_timestamp_from_another_session_is_not_found(): void
    {
        [, $coach, $session] = $this->timelineReadySession();
        $other = Session::factory()->create(['status' => Session::STATUS_TIMELINE_READY]);
        $foreign = GameEvent::factory()->for($other)->create(['type' => 'kill', 'side' => 'ally', 'match_time_ms' => 1000]);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$foreign->id}/review", ['reviewed' => true])
            ->assertNotFound();
    }

    public function test_a_player_cannot_review(): void
    {
        [, , $session] = $this->timelineReadySession(game: 1);
        $player = $this->playerOn($session);
        $event = GameEvent::sole();

        $this->actingAs($player, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}/review", ['reviewed' => true])
            ->assertForbidden();
    }

    public function test_review_is_refused_before_the_timeline_is_ready(): void
    {
        [, $coach, $session] = $this->timelineReadySession(game: 1, status: Session::STATUS_PROCESSING);
        $event = GameEvent::sole();

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}/review", ['reviewed' => true])
            ->assertForbidden();
    }
}
