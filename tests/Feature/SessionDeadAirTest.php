<?php

namespace Tests\Feature;

use App\Jobs\AdvanceSessionAfterProcessing;
use App\Jobs\DetectDeadAir;
use App\Models\Annotation;
use App\Models\AodRecord;
use App\Models\CommEvent;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\Transcript;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Dead-air detection: DetectDeadAir writes team-aggregate silent periods for
 * every session once its comm events are detected, annotates any period with an
 * uncalled game event inside it, gates timeline_ready by its own marker, and
 * clears on reanalyze. See docs/adr/0009-dead-air-detection.md.
 */
class SessionDeadAirTest extends TestCase
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
     * A processing session with one completed, detected transcript whose
     * recording is $windowMs long. The callback adds comm-event talk spans.
     *
     * @param  callable(Transcript): void|null  $withSpans
     * @return array{0: Team, 1: User, 2: Session, 3: Transcript}
     */
    private function deadAirSession(?callable $withSpans = null, int $windowMs = 120000, string $status = Session::STATUS_PROCESSING): array
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
            'audio_duration_ms' => $windowMs,
            'comm_events_detected' => true,
        ]);

        if ($withSpans) {
            $withSpans($transcript);
        }

        return [$team, $coach, $session, $transcript];
    }

    private function talkSpan(Transcript $transcript, int $startMs, int $endMs): CommEvent
    {
        return CommEvent::create([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_DECLARATIVE,
            'is_redundant' => false,
            'start_ms' => $startMs,
            'end_ms' => $endMs,
            'content' => 'talk',
            'padding_ms' => 2000,
        ]);
    }

    public function test_it_writes_periods_sets_the_marker_and_redispatches_advance(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
            $this->talkSpan($t, 60000, 61000);
        });

        (new DetectDeadAir($session))->handle();

        // Gaps over the 5s default threshold: [0,10000], [11000,60000], [61000,120000].
        $this->assertSame(
            [[0, 10000], [11000, 60000], [61000, 120000]],
            $session->deadAirPeriods()->get()->map(fn ($p) => [$p->start_ms, $p->end_ms])->all(),
        );
        $this->assertNotNull($session->fresh()->dead_air_detected_at);
        Bus::assertDispatched(AdvanceSessionAfterProcessing::class);
    }

    public function test_a_period_with_an_uncalled_game_event_is_annotated_one_without_is_not(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
            $this->talkSpan($t, 60000, 61000);
        });

        // Sits inside the [11000, 60000] period; nothing in the other two.
        $plant = GameEvent::factory()->for($session)->create([
            'type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 30000,
        ]);

        (new DetectDeadAir($session))->handle();

        $this->assertDatabaseCount('dead_air_periods', 3);
        $annotation = Annotation::sole();
        $this->assertSame(Annotation::TOPIC_DEAD_AIR, $annotation->topic);
        $this->assertNull($annotation->assessment);
        $this->assertNull($annotation->alignment_window_ms);
        $this->assertSame([$plant->id], $annotation->game_event_ids);
        $this->assertStringContainsString('enemy spike_plant', $annotation->body);
        $this->assertStringEndsWith('went uncalled. Consider reviewing this moment.', $annotation->body);
    }

    public function test_each_period_snapshots_the_team_dead_air_threshold(): void
    {
        Bus::fake();

        [$team, , $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
        }, windowMs: 40000);

        $team->ensureSettings()->get(TeamSettings::SETTING_DEAD_AIR_THRESHOLD)->update(['setting_parameter' => 8000]);

        (new DetectDeadAir($session))->handle();

        $this->assertSame([8000, 8000], $session->deadAirPeriods()->pluck('dead_air_threshold_ms')->all());
    }

    public function test_the_job_is_idempotent(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
        });

        (new DetectDeadAir($session))->handle();
        (new DetectDeadAir($session->fresh()))->handle();

        $this->assertSame(2, $session->deadAirPeriods()->count());
    }

    public function test_a_permanent_failure_marks_the_session_and_lets_the_fan_in_advance(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
        });

        (new DetectDeadAir($session))->failed(new \RuntimeException('boom'));

        $this->assertNotNull($session->fresh()->dead_air_detected_at);
        $this->assertDatabaseCount('dead_air_periods', 0);
        Bus::assertDispatched(AdvanceSessionAfterProcessing::class);
    }

    public function test_a_failure_after_the_session_left_processing_is_a_no_op(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession(status: Session::STATUS_TIMELINE_READY);

        (new DetectDeadAir($session))->failed(new \RuntimeException('boom'));

        $this->assertNull($session->fresh()->dead_air_detected_at);
        Bus::assertNotDispatched(AdvanceSessionAfterProcessing::class);
    }

    public function test_advance_holds_every_session_until_dead_air_runs(): void
    {
        Bus::fake();

        [, , $session] = $this->deadAirSession();

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
        Bus::assertDispatched(DetectDeadAir::class);
    }

    public function test_advance_moves_to_timeline_ready_once_the_dead_air_marker_is_set(): void
    {
        [, , $session] = $this->deadAirSession();
        $session->update(['dead_air_detected_at' => now()]);

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }

    public function test_timeline_merges_dead_air_periods_into_the_game_events_spine(): void
    {
        [, $coach, $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
            $this->talkSpan($t, 60000, 61000);
        });

        GameEvent::factory()->for($session)->create([
            'type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 30000,
        ]);

        (new DetectDeadAir($session))->handle();
        $session->update(['status' => Session::STATUS_TIMELINE_READY]);

        // Spine sorted by start_ms: dead_air[0,10000], dead_air[11000,60000]
        // (annotated), game_event@30000, dead_air[61000,120000].
        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.game_events.0.type', 'dead_air')
            ->assertJsonPath('data.game_events.0.start_ms', 0)
            ->assertJsonPath('data.game_events.0.duration_ms', 10000)
            ->assertJsonPath('data.game_events.0.annotations', [])
            ->assertJsonPath('data.game_events.1.type', 'dead_air')
            ->assertJsonPath('data.game_events.1.annotations.0.topic', 'dead_air')
            ->assertJsonPath('data.game_events.1.annotations.0.assessment', null)
            ->assertJsonPath('data.game_events.2.type', 'game_event')
            ->assertJsonPath('data.game_events.2.game_type', 'spike_plant')
            ->assertJsonPath('data.game_events.3.type', 'dead_air');
    }

    public function test_timeline_summary_reports_the_dead_air_count_and_longest(): void
    {
        [, $coach, $session] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
            $this->talkSpan($t, 60000, 61000);
        });

        (new DetectDeadAir($session))->handle();
        $session->update(['status' => Session::STATUS_TIMELINE_READY]);

        // Periods [0,10000], [11000,60000], [61000,120000]; longest is 59000.
        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.team.dead_air.count', 3)
            ->assertJsonPath('data.team.dead_air.longest_ms', 59000);
    }

    public function test_reanalyze_clears_the_dead_air_marker_and_its_rows(): void
    {
        [, , $session, $transcript] = $this->deadAirSession(function (Transcript $t) {
            $this->talkSpan($t, 10000, 11000);
        }, status: Session::STATUS_TIMELINE_READY);

        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'enemy', 'match_time_ms' => 30000]);
        $session->update(['status' => Session::STATUS_PROCESSING]);
        (new DetectDeadAir($session))->handle();
        $session->update(['status' => Session::STATUS_TIMELINE_READY]);

        $this->assertGreaterThan(0, $session->deadAirPeriods()->count());
        $this->assertSame(1, Annotation::where('topic', Annotation::TOPIC_DEAD_AIR)->count());

        Bus::fake();
        $session->fresh()->reanalyze();

        $this->assertNull($session->fresh()->dead_air_detected_at);
        $this->assertDatabaseCount('dead_air_periods', 0);
        $this->assertSame(0, Annotation::where('topic', Annotation::TOPIC_DEAD_AIR)->count());
    }
}
