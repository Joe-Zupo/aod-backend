<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\Transcript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * The review columns on the three timestamp tables and the reply column on
 * annotations (see docs/adr/0010-timeline-management.md).
 */
class ReviewStateTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function coachAndSession(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        return [$coach, $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY)];
    }

    public function test_a_dead_air_period_can_be_marked_reviewed_and_cleared(): void
    {
        [$coach, $session] = $this->coachAndSession();
        $period = DeadAirPeriod::create(['session_id' => $session->id, 'start_ms' => 0, 'end_ms' => 8000, 'dead_air_threshold_ms' => 5000]);

        $this->assertNull($period->reviewed_at);

        $period->markReviewed($coach);
        $period->refresh();
        $this->assertNotNull($period->reviewed_at);
        $this->assertSame($coach->id, $period->reviewed_by);

        $period->clearReview();
        $this->assertNull($period->fresh()->reviewed_at);
    }

    public function test_reviewed_and_unreviewed_scopes_partition_the_rows(): void
    {
        [$coach, $session] = $this->coachAndSession();
        GameEvent::factory()->for($session)->create(['type' => 'round_win', 'side' => null, 'match_time_ms' => 1000]);
        $b = GameEvent::factory()->for($session)->create(['type' => 'kill', 'side' => 'ally', 'match_time_ms' => 2000]);
        $b->markReviewed($coach);

        $this->assertSame(1, GameEvent::reviewed()->count());
        $this->assertSame(1, GameEvent::unreviewed()->count());
    }

    public function test_the_detection_snapshot_columns_are_nullable_now(): void
    {
        [$coach, $session] = $this->coachAndSession();
        $transcript = Transcript::factory()->create();

        $comm = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 1000, 'end_ms' => 2000, 'content' => 'coach note', 'padding_ms' => null, 'created_by' => $coach->id,
        ]);
        $period = DeadAirPeriod::create([
            'session_id' => $session->id, 'start_ms' => 0, 'end_ms' => 8000, 'dead_air_threshold_ms' => null, 'created_by' => $coach->id,
        ]);

        $this->assertNull($comm->fresh()->padding_ms);
        $this->assertNull($period->fresh()->dead_air_threshold_ms);
    }

    public function test_an_annotation_has_one_level_of_replies(): void
    {
        [$coach, $session] = $this->coachAndSession();
        $transcript = Transcript::factory()->create();
        $comm = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 1000, 'end_ms' => 2000, 'content' => 'x', 'padding_ms' => 2000,
        ]);

        $note = $comm->annotations()->create([
            'user_id' => null, 'topic' => Annotation::TOPIC_DEAD_AIR, 'assessment' => null,
            'body' => 'system says', 'game_event_ids' => [], 'alignment_window_ms' => null,
        ]);
        $reply = $comm->annotations()->create([
            'parent_id' => $note->id, 'user_id' => $coach->id, 'topic' => Annotation::TOPIC_REPLY,
            'body' => 'coach replies', 'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);

        $this->assertTrue($note->isSystem());
        $this->assertFalse($note->isReply());
        $this->assertTrue($reply->isReply());
        $this->assertSame([$reply->id], $note->replies()->pluck('id')->all());
        $this->assertSame($note->id, $reply->parent->id);
    }
}
