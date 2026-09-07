<?php

namespace Tests\Feature;

use App\Jobs\AdvanceSessionAfterProcessing;
use App\Jobs\AssessGameStateAlignment;
use App\Models\Annotation;
use App\Models\AodRecord;
use App\Models\CalloutDetection;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\Transcript;
use App\Models\TranscriptWord;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Game-state alignment: the AssessGameStateAlignment job writes a system
 * annotation on each communication event whose keyword makes a checkable claim
 * or that has a game event nearby, it gates timeline_ready when the session has
 * game events, and its output surfaces on the timeline endpoints. See
 * docs/adr/0008-game-state-alignment.md and its 2026-09-07 amendment.
 */
class SessionGameStateAlignmentTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    /**
     * A processing session with one completed, detected transcript for one
     * player. The callback adds comm events / callouts; game events and the
     * status are set by the caller.
     *
     * @param  callable(Transcript, SessionParticipant): void|null  $withEvents
     * @return array{0: Team, 1: User, 2: Session, 3: SessionParticipant, 4: Transcript}
     */
    private function alignableSession(?callable $withEvents = null, string $status = Session::STATUS_PROCESSING): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, $status);
        $session->timeline()->create();
        $this->addParticipant($session, $coach, 'main_coach');

        $player = $this->addParticipant(
            $session,
            $this->makeAndAttachMember($team, 'player', 'Player', $coach),
            'player',
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        );

        $aod = AodRecord::factory()->for($player)->create();
        VodRecord::factory()->for($player)->create();

        $transcript = Transcript::factory()->for($aod)->completed()->create([
            'audio_duration_ms' => 120000,
            'comm_events_detected' => true,
        ]);

        if ($withEvents) {
            $withEvents($transcript, $player);
        }

        return [$team, $coach, $session, $player, $transcript];
    }

    private function commEventWithKeyword(Transcript $transcript, string $keyword, int $startMs, int $endMs): CommEvent
    {
        $event = CommEvent::create([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_DECLARATIVE,
            'is_redundant' => false,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'content' => $keyword,
            'padding_ms' => 2000,
        ]);

        $word = TranscriptWord::create([
            'transcript_id' => $transcript->id,
            'position' => 0,
            'sentence_position' => 0,
            'word' => $keyword,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'confidence' => 0.9,
        ]);

        CalloutDetection::create([
            'comm_event_id' => $event->id,
            'transcript_word_id' => $word->id,
            'keyword' => $keyword,
            'normalized_keyword' => $keyword,
            'category' => 'declarative',
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'confidence' => 0.9,
        ]);

        return $event;
    }

    public function test_a_mapped_callout_with_a_favourable_corroborating_event_is_possibly_positive(): void
    {
        [, , $session, , $transcript] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });

        GameEvent::factory()->for($session)->create([
            'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21800,
        ]);

        (new AssessGameStateAlignment($session))->handle();

        $annotation = Annotation::sole();

        $this->assertSame(Annotation::TOPIC_GAME_STATE_ALIGNMENT, $annotation->topic);
        $this->assertSame(Annotation::ASSESSMENT_POSSIBLY_POSITIVE, $annotation->assessment);
        $this->assertNull($annotation->user_id);
        $this->assertSame(5000, $annotation->alignment_window_ms);
        $this->assertSame(
            CommEvent::sole()->id,
            $annotation->annotatable_id,
        );
        $this->assertStringNotContainsString('Consider reviewing this moment.', $annotation->body);
    }

    public function test_a_callout_with_no_claim_and_no_nearby_game_event_gets_no_annotation(): void
    {
        [, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'rotating', 20000, 21000);
        });

        GameEvent::factory()->for($session)->create([
            'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 90000,
        ]);

        (new AssessGameStateAlignment($session))->handle();

        $this->assertDatabaseCount('annotations', 0);
    }

    public function test_a_callout_that_maps_no_kind_takes_its_sign_from_the_nearby_game_state(): void
    {
        [, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'rotating', 20000, 21000);
        });

        // An enemy kill sitting in the window makes the moment possibly_negative
        // even though "rotating" claims nothing about kills.
        $kill = GameEvent::factory()->for($session)->create([
            'type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 22000, 'note' => 'Sova',
        ]);

        (new AssessGameStateAlignment($session))->handle();

        $annotation = Annotation::sole();
        $this->assertSame(Annotation::ASSESSMENT_POSSIBLY_NEGATIVE, $annotation->assessment);
        $this->assertSame([$kill->id], $annotation->game_event_ids);
        $this->assertStringContainsString('Sova', $annotation->body);
        $this->assertStringContainsString('Consider reviewing this moment.', $annotation->body);
    }

    public function test_a_contradiction_pair_event_is_possibly_negative_with_the_nudge(): void
    {
        [, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });

        GameEvent::factory()->for($session)->create([
            'type' => 'spike_defuse', 'side' => 'ally', 'match_time_ms' => 22000,
        ]);

        (new AssessGameStateAlignment($session))->handle();

        $annotation = Annotation::sole();
        $this->assertSame(Annotation::ASSESSMENT_POSSIBLY_NEGATIVE, $annotation->assessment);
        $this->assertStringContainsString('Consider reviewing this moment.', $annotation->body);
    }

    public function test_the_job_is_idempotent_and_rewrites_its_own_rows(): void
    {
        [, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });

        GameEvent::factory()->for($session)->create([
            'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21800,
        ]);

        (new AssessGameStateAlignment($session))->handle();
        (new AssessGameStateAlignment($session->fresh()))->handle();

        $this->assertDatabaseCount('annotations', 1);
    }

    public function test_the_job_snapshots_the_team_game_alignment_window(): void
    {
        [$team, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });

        $team->ensureSettings()->get(TeamSettings::SETTING_GAME_ALIGNMENT_WINDOW)->update(['setting_parameter' => 9000]);

        // 8s after the span end: outside the default 5s window, inside 9s.
        GameEvent::factory()->for($session)->create([
            'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 29000,
        ]);

        (new AssessGameStateAlignment($session))->handle();

        $annotation = Annotation::sole();
        $this->assertSame(9000, $annotation->alignment_window_ms);
        $this->assertSame(Annotation::ASSESSMENT_POSSIBLY_POSITIVE, $annotation->assessment);
    }

    public function test_running_alignment_sets_the_marker_and_re_dispatches_advance(): void
    {
        Bus::fake();

        [, , $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });
        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21800]);

        (new AssessGameStateAlignment($session))->handle();

        $this->assertNotNull($session->fresh()->game_alignment_assessed_at);
        Bus::assertDispatched(AdvanceSessionAfterProcessing::class);
    }

    public function test_advance_holds_a_processing_session_with_game_events_until_alignment_runs(): void
    {
        Bus::fake();

        [, , $session] = $this->alignableSession();
        GameEvent::factory()->for($session)->create(['type' => 'round_win', 'side' => null, 'match_time_ms' => 1000]);

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
        Bus::assertDispatched(AssessGameStateAlignment::class);
    }

    public function test_advance_moves_to_timeline_ready_once_the_alignment_marker_is_set(): void
    {
        [, , $session] = $this->alignableSession();
        GameEvent::factory()->for($session)->create(['type' => 'round_win', 'side' => null, 'match_time_ms' => 1000]);
        $session->update(['game_alignment_assessed_at' => now()]);

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }

    public function test_advance_skips_alignment_for_a_session_with_no_game_events(): void
    {
        Bus::fake();

        [, , $session] = $this->alignableSession();

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
        Bus::assertNotDispatched(AssessGameStateAlignment::class);
    }

    public function test_reanalyze_clears_the_alignment_marker_and_its_rows(): void
    {
        [, , $session, , $transcript] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        }, Session::STATUS_TIMELINE_READY);

        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21800]);
        Annotation::factory()->for(CommEvent::sole(), 'annotatable')->create();
        $session->update(['game_alignment_assessed_at' => now()]);

        Bus::fake();
        $session->fresh()->reanalyze();

        $this->assertNull($session->fresh()->game_alignment_assessed_at);
        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
        $this->assertDatabaseCount('annotations', 0);
    }

    public function test_timeline_embeds_alignment_annotations_on_the_communication_event_entry(): void
    {
        [, $coach, $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
        });

        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21800]);
        (new AssessGameStateAlignment($session))->handle();
        $session->update(['status' => Session::STATUS_TIMELINE_READY]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.topic', 'game_state_alignment')
            ->assertJsonPath('data.participants.0.timestamps.0.annotations.0.assessment', 'possibly_positive')
            ->assertJsonStructure([
                'data' => ['participants' => [['timestamps' => [['annotations' => [['topic', 'assessment', 'body', 'game_event_ids']]]]]]],
            ]);
    }

    public function test_timeline_gives_an_unassessed_communication_event_an_empty_annotations_array(): void
    {
        [, $coach, $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'rotating', 20000, 21000);
        }, Session::STATUS_TIMELINE_READY);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.participants.0.timestamps.0.annotations', []);
    }

    public function test_timeline_summary_reports_alignment_counts_at_team_and_player_level(): void
    {
        [, $coach, $session] = $this->alignableSession(function (Transcript $t) {
            $this->commEventWithKeyword($t, 'planting', 20000, 21000);
            $this->commEventWithKeyword($t, 'defusing', 40000, 41000);
            // No mapped keyword, but an enemy kill lands nearby: a narration
            // row that takes a possibly_negative sign from that game state.
            $this->commEventWithKeyword($t, 'rotating', 60000, 61000);
        });

        // Corroborates the plant call, favourably.
        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 21500]);
        // Contradicts the defuse call (enemy plant in its window).
        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 41500]);
        // Near the rotating call.
        GameEvent::factory()->for($session)->create(['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 62000]);

        (new AssessGameStateAlignment($session))->handle();
        $session->update(['status' => Session::STATUS_TIMELINE_READY]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.team.alignment.possibly_positive', 1)
            ->assertJsonPath('data.team.alignment.possibly_negative', 2)
            ->assertJsonPath('data.team.alignment.neutral', 0)
            ->assertJsonPath('data.team.alignment.assessed_total', 3)
            ->assertJsonPath('data.participants.0.alignment.possibly_positive', 1)
            ->assertJsonPath('data.participants.0.alignment.possibly_negative', 2);
    }
}
