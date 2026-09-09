<?php

namespace Tests\Feature\TimelineManagement;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\TestCase;

/**
 * Coach create / edit / delete over the three timestamp kinds
 * (docs/adr/0010-timeline-management.md).
 */
class TimestampCrudTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_a_coach_creates_a_communication_event_on_a_participant(): void
    {
        [, $coach, $session, $player] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps", [
                'type' => 'communication_event',
                'participant_id' => $player->id,
                'communication_type' => 'compound',
                'content' => 'should have called rotate',
                'start_ms' => 40000,
                'end_ms' => 42000,
            ])
            ->assertCreated();

        $row = CommEvent::sole();
        $this->assertSame('compound', $row->communication_type);
        $this->assertSame($coach->id, $row->created_by);
        $this->assertNotNull($row->reviewed_at);
        $this->assertNull($row->padding_ms);
    }

    public function test_a_coach_creates_a_dead_air_period(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps", [
                'type' => 'dead_air', 'start_ms' => 50000, 'end_ms' => 61000,
            ])
            ->assertCreated();

        $this->assertSame($coach->id, DeadAirPeriod::sole()->created_by);
        $this->assertNull(DeadAirPeriod::sole()->dead_air_threshold_ms);
    }

    public function test_a_coach_cannot_create_a_game_event(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps", [
                'type' => 'game_event', 'start_ms' => 1000, 'end_ms' => 1000,
            ])
            ->assertStatus(422);
    }

    public function test_a_span_past_the_session_window_is_rejected(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps", [
                'type' => 'dead_air', 'start_ms' => 10000, 'end_ms' => 400000,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['end_ms']]]);
    }

    public function test_editing_a_timestamp_clears_its_review(): void
    {
        [, $coach, $session] = $this->timelineReadySession(deadAir: 1);
        $period = DeadAirPeriod::sole();
        $period->markReviewed($coach);

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/dead_air/{$period->id}", ['end_ms' => 120000])
            ->assertOk();

        $period->refresh();
        $this->assertSame(120000, $period->end_ms);
        $this->assertNull($period->reviewed_at);
    }

    public function test_a_round_game_event_cannot_be_given_a_side(): void
    {
        [, $coach, $session] = $this->timelineReadySession(game: 1);
        $event = GameEvent::sole();

        $this->actingAs($coach, 'sanctum')
            ->putJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}", [
                'type' => 'round_win', 'side' => 'ally',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['side']]]);
    }

    public function test_deleting_a_comm_event_takes_its_annotation_subtree(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $comm = CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 10000, 'end_ms' => 11000, 'content' => 'x', 'padding_ms' => 2000,
        ]);
        $note = $comm->annotations()->create([
            'user_id' => null, 'topic' => Annotation::TOPIC_GAME_STATE_ALIGNMENT, 'assessment' => 'neutral',
            'body' => 'sys', 'game_event_ids' => [], 'alignment_window_ms' => 5000,
        ]);
        $comm->annotations()->create([
            'parent_id' => $note->id, 'user_id' => $coach->id, 'topic' => Annotation::TOPIC_REPLY,
            'body' => 'reply', 'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);

        $this->actingAs($coach, 'sanctum')
            ->deleteJson("/api/sessions/{$session->id}/timestamps/communication_event/{$comm->id}")
            ->assertOk();

        $this->assertDatabaseMissing('comm_events', ['id' => $comm->id]);
        $this->assertDatabaseCount('annotations', 0);
    }

    public function test_a_player_cannot_create_or_delete_timestamps(): void
    {
        [, , $session, $player] = $this->timelineReadySession(game: 1);
        $watcher = $this->playerOn($session);
        $event = GameEvent::sole();

        $this->actingAs($watcher, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps", ['type' => 'dead_air', 'start_ms' => 1, 'end_ms' => 2])
            ->assertForbidden();
        $this->actingAs($watcher, 'sanctum')
            ->deleteJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}")
            ->assertForbidden();
    }
}
