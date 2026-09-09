<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * The review / authorship fields on every timeline entry, the nested reply
 * tree on annotations, and the coach-only review figure on timeline-summary
 * (docs/adr/0010-timeline-management.md).
 */
class TimelinePayloadTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_game_event_entry_carries_review_and_authorship_fields(): void
    {
        [, $coach, $session] = $this->timelineReadySession(game: 1);
        $event = GameEvent::sole();
        $event->markReviewed($coach);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.game_events.0.reviewed', true)
            ->assertJsonPath('data.game_events.0.reviewed_by', $coach->id)
            ->assertJsonPath('data.game_events.0.created_by', null);
    }

    public function test_a_coach_note_and_its_reply_nest_on_the_entry(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $comm = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 10000, 'end_ms' => 11000, 'content' => 'x', 'padding_ms' => 2000,
        ]);
        $note = $comm->annotations()->create([
            'user_id' => $coach->id, 'topic' => Annotation::TOPIC_NOTE, 'body' => 'look here',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);
        $comm->annotations()->create([
            'parent_id' => $note->id, 'annotatable_type' => $note->annotatable_type, 'annotatable_id' => $note->annotatable_id,
            'user_id' => $coach->id, 'topic' => Annotation::TOPIC_REPLY, 'body' => 'agreed',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.id', $note->id)
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.author.username', $coach->username)
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.parent_id', null)
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.replies.0.body', 'agreed')
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.replies.0.replies', []);
    }

    public function test_a_system_annotation_has_a_null_author(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $comm = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 10000, 'end_ms' => 11000, 'content' => 'x', 'padding_ms' => 2000,
        ]);
        $comm->annotations()->create([
            'user_id' => null, 'topic' => Annotation::TOPIC_DEAD_AIR, 'body' => 'sys',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.author', null);
    }

    public function test_the_review_figure_is_coach_only_and_timeline_ready_only(): void
    {
        [, $coach, $session] = $this->timelineReadySession(comm: 2, game: 1);
        GameEvent::sole()->markReviewed($coach);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.team.review.reviewed', 1)
            ->assertJsonPath('data.team.review.total', 3)
            ->assertJsonPath('data.team.review.complete', false)
            ->assertJsonPath('data.participants.0.review.total', 2);

        // Player never sees it (and cannot reach the endpoint while under review),
        // and it is gone once analysis_ready.
        $session->commEventsQuery()->update(['reviewed_at' => now(), 'reviewed_by' => $coach->id]);
        $session->deadAirPeriods()->update(['reviewed_at' => now(), 'reviewed_by' => $coach->id]);
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonMissingPath('data.team.review');
    }
}
