<?php

namespace Tests\Feature;

use App\Models\Annotation;
use App\Models\DeadAirPeriod;
use App\Models\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * The dead-air period entity: session-owned, ordered by start, carrying its
 * own polymorphic annotations, cascading on session delete (see
 * docs/adr/0009-dead-air-detection.md).
 */
class DeadAirPeriodTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function readySession(): Session
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        return $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);
    }

    public function test_a_session_returns_its_dead_air_periods_ordered_by_start(): void
    {
        $session = $this->readySession();

        DeadAirPeriod::create(['session_id' => $session->id, 'start_ms' => 40000, 'end_ms' => 48000, 'dead_air_threshold_ms' => 5000]);
        DeadAirPeriod::create(['session_id' => $session->id, 'start_ms' => 10000, 'end_ms' => 20000, 'dead_air_threshold_ms' => 5000]);

        $this->assertSame(
            [10000, 40000],
            $session->deadAirPeriods()->pluck('start_ms')->all(),
        );
    }

    public function test_a_period_carries_polymorphic_dead_air_annotations(): void
    {
        $session = $this->readySession();
        $period = DeadAirPeriod::create([
            'session_id' => $session->id, 'start_ms' => 10000, 'end_ms' => 20000, 'dead_air_threshold_ms' => 5000,
        ]);

        $period->annotations()->create([
            'user_id' => null,
            'topic' => Annotation::TOPIC_DEAD_AIR,
            'assessment' => null,
            'body' => '10s of team silence.',
            'game_event_ids' => [],
            'alignment_window_ms' => null,
        ]);

        $annotation = $period->annotations()->sole();
        $this->assertSame(Annotation::TOPIC_DEAD_AIR, $annotation->topic);
        $this->assertSame((new DeadAirPeriod)->getMorphClass(), $annotation->annotatable_type);
        $this->assertSame($period->id, $annotation->annotatable_id);
    }

    public function test_deleting_a_session_cascades_its_dead_air_periods(): void
    {
        $session = $this->readySession();
        DeadAirPeriod::create(['session_id' => $session->id, 'start_ms' => 10000, 'end_ms' => 20000, 'dead_air_threshold_ms' => 5000]);

        $session->delete();

        $this->assertDatabaseCount('dead_air_periods', 0);
    }

    public function test_the_dead_air_marker_casts_to_a_datetime(): void
    {
        $session = $this->readySession();
        $session->update(['dead_air_detected_at' => now()]);

        $this->assertInstanceOf(Carbon::class, $session->fresh()->dead_air_detected_at);
    }
}
