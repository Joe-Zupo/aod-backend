<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * timeline_ready <-> analysis_ready, its all-reviewed gate, and reanalyze()
 * discarding coach work (docs/adr/0010-timeline-management.md).
 */
class AnalysisReadyTransitionTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function reviewEverything(Session $session, $coach): void
    {
        $session->commEventsQuery()->update(['reviewed_at' => now(), 'reviewed_by' => $coach->id]);
        $session->gameEvents()->update(['reviewed_at' => now(), 'reviewed_by' => $coach->id]);
        $session->deadAirPeriods()->update(['reviewed_at' => now(), 'reviewed_by' => $coach->id]);
    }

    public function test_analysis_ready_is_refused_while_timestamps_are_unreviewed(): void
    {
        [, $coach, $session] = $this->timelineReadySession(comm: 2, game: 1, deadAir: 1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => '4 timestamp(s) still need review.']);

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }

    public function test_a_coach_marks_analysis_ready_once_everything_is_reviewed(): void
    {
        Bus::fake();
        [, $coach, $session] = $this->timelineReadySession(comm: 2, game: 1, deadAir: 1);
        $this->reviewEverything($session, $coach);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_ANALYSIS_READY);

        $this->assertSame(Session::STATUS_ANALYSIS_READY, $session->fresh()->status);
    }

    public function test_a_zero_timestamp_session_can_be_marked_analysis_ready(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertOk();
    }

    public function test_marking_analysis_ready_stamps_analysis_ready_at(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->assertNull($session->analysis_ready_at);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertOk();

        $this->assertNotNull($session->fresh()->analysis_ready_at);
    }

    public function test_reopening_review_leaves_analysis_ready_at_set(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertOk();
        $stamp = $session->fresh()->analysis_ready_at;

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'timeline_ready'])
            ->assertOk();

        $this->assertNotNull($stamp);
        $this->assertEquals($stamp, $session->fresh()->analysis_ready_at);
    }

    public function test_a_player_cannot_mark_analysis_ready(): void
    {
        [, , $session] = $this->timelineReadySession();
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertForbidden();
    }

    public function test_reopen_review_returns_to_timeline_ready_and_keeps_review_stamps(): void
    {
        [, $coach, $session] = $this->timelineReadySession(comm: 1);
        $this->reviewEverything($session, $coach);
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'timeline_ready'])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_TIMELINE_READY);

        $this->assertNotNull(CommEvent::sole()->reviewed_at);
    }

    public function test_reopen_review_is_refused_from_timeline_ready(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'timeline_ready'])
            ->assertStatus(422);
    }

    public function test_reanalyze_from_analysis_ready_discards_all_coach_work(): void
    {
        Bus::fake();
        [, $coach, $session, , $transcript] = $this->timelineReadySession(comm: 1, game: 1);
        $this->reviewEverything($session, $coach);

        // A coach-created comm event, a coach note on a system row, and a reply.
        $manual = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'informative', 'is_redundant' => false,
            'start_ms' => 5000, 'end_ms' => 6000, 'content' => 'coach add', 'padding_ms' => null,
            'created_by' => $coach->id, 'reviewed_at' => now(), 'reviewed_by' => $coach->id,
        ]);
        $note = $manual->annotations()->create([
            'user_id' => $coach->id, 'topic' => Annotation::TOPIC_NOTE, 'body' => 'mine',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);
        GameEvent::first()->markReviewed($coach);
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $session->fresh()->reanalyze();

        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
        $this->assertDatabaseMissing('comm_events', ['id' => $manual->id]);
        $this->assertDatabaseMissing('annotations', ['id' => $note->id]);
        $this->assertSame(0, GameEvent::whereNotNull('reviewed_at')->count());
    }
}
