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
 * Coach notes and one-level replies, including replies to system annotations
 * (docs/adr/0010-timeline-management.md).
 */
class AnnotationEndpointsTest extends TestCase
{
    use BuildsTimeline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function systemNoteOn(CommEvent $comm): Annotation
    {
        return $comm->annotations()->create([
            'user_id' => null, 'topic' => Annotation::TOPIC_DEAD_AIR, 'assessment' => null,
            'body' => 'system', 'game_event_ids' => [], 'alignment_window_ms' => null,
        ]);
    }

    private function commOn($transcript): CommEvent
    {
        return CommEvent::create([
            'transcript_id' => $transcript->id, 'communication_type' => 'declarative', 'is_redundant' => false,
            'start_ms' => 10000, 'end_ms' => 11000, 'content' => 'x', 'padding_ms' => 2000,
        ]);
    }

    public function test_a_coach_adds_a_note_to_a_game_event(): void
    {
        [, $coach, $session] = $this->timelineReadySession(game: 1);
        $event = GameEvent::sole();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/timestamps/game_event/{$event->id}/annotations", ['body' => 'watch this'])
            ->assertCreated();

        $note = Annotation::sole();
        $this->assertSame(Annotation::TOPIC_NOTE, $note->topic);
        $this->assertSame($coach->id, $note->user_id);
        $this->assertNull($note->parent_id);
    }

    public function test_a_player_creates_a_note_only_once_analysis_ready(): void
    {
        [, , $session, , $transcript] = $this->timelineReadySession();
        $comm = $this->commOn($transcript);
        $player = $this->playerOn($session);
        $url = "/api/sessions/{$session->id}/timestamps/communication_event/{$comm->id}/annotations";

        $this->actingAs($player, 'sanctum')->postJson($url, ['body' => 'too early'])->assertForbidden();

        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($player, 'sanctum')->postJson($url, ['body' => 'now ok'])->assertCreated();

        $note = Annotation::where('topic', Annotation::TOPIC_NOTE)->sole();
        $this->assertSame($player->id, $note->user_id);
    }

    public function test_a_coach_replies_to_a_system_annotation(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $note = $this->systemNoteOn($this->commOn($transcript));

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/annotations/{$note->id}/replies", ['body' => 'disagree'])
            ->assertCreated();

        $reply = Annotation::where('topic', Annotation::TOPIC_REPLY)->sole();
        $this->assertSame($note->id, $reply->parent_id);
        $this->assertSame($note->annotatable_id, $reply->annotatable_id);
    }

    public function test_a_reply_to_a_reply_is_rejected(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $note = $this->systemNoteOn($this->commOn($transcript));
        $reply = $note->replies()->create([
            'annotatable_type' => $note->annotatable_type, 'annotatable_id' => $note->annotatable_id,
            'user_id' => $coach->id, 'topic' => Annotation::TOPIC_REPLY, 'body' => 'first',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/annotations/{$reply->id}/replies", ['body' => 'nested'])
            ->assertStatus(422);
    }

    public function test_a_system_annotation_cannot_be_edited_or_deleted(): void
    {
        [, $coach, $session, , $transcript] = $this->timelineReadySession();
        $note = $this->systemNoteOn($this->commOn($transcript));

        $this->actingAs($coach, 'sanctum')->putJson("/api/annotations/{$note->id}", ['body' => 'x'])->assertForbidden();
        $this->actingAs($coach, 'sanctum')->deleteJson("/api/annotations/{$note->id}")->assertForbidden();
    }

    public function test_only_the_author_edits_a_coach_note(): void
    {
        [$team, $coachA, $session, , $transcript] = $this->timelineReadySession();
        $coachB = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $coachA);
        $comm = $this->commOn($transcript);
        $note = $comm->annotations()->create([
            'user_id' => $coachA->id, 'topic' => Annotation::TOPIC_NOTE, 'body' => 'A wrote this',
            'game_event_ids' => [], 'assessment' => null, 'alignment_window_ms' => null,
        ]);

        $this->actingAs($coachB, 'sanctum')->putJson("/api/annotations/{$note->id}", ['body' => 'B edits'])->assertForbidden();
        $this->actingAs($coachA, 'sanctum')->putJson("/api/annotations/{$note->id}", ['body' => 'A edits'])->assertOk();
        $this->assertSame('A edits', $note->fresh()->body);
    }

    public function test_a_player_can_reply_only_once_analysis_ready(): void
    {
        [, , $session, , $transcript] = $this->timelineReadySession();
        $note = $this->systemNoteOn($this->commOn($transcript));
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/annotations/{$note->id}/replies", ['body' => 'too early'])
            ->assertForbidden();

        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/annotations/{$note->id}/replies", ['body' => 'now ok'])
            ->assertCreated();
    }
}
