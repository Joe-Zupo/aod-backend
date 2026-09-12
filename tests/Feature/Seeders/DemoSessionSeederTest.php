<?php

namespace Tests\Feature\Seeders;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use Database\Seeders\DemoSessionSeeder;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoSessionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        (new TeamSeeder)->run();
    }

    private function runDemoSeeder(): void
    {
        (new DemoSessionSeeder)->run();
    }

    private function thunderbolts(): Team
    {
        return Team::where('team_name', 'Thunderbolts')->firstOrFail();
    }

    public function test_it_creates_an_analysis_ready_session_with_five_recorded_players(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $recorded = $session->participants()->where('participant_role', 'player')->get();

        $this->assertCount(5, $recorded);

        foreach ($recorded as $participant) {
            $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_COMPLETED, $participant->participant_status);
            $this->assertNotNull($participant->aodRecord);
            $this->assertNotNull($participant->vodRecord);
            $this->assertNotNull($participant->aodRecord->transcript);
        }
    }

    public function test_its_communication_events_cover_all_three_types(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $types = $session->commEventsQuery()->pluck('communication_type')->unique()->sort()->values()->all();

        $this->assertSame(
            [CommEvent::TYPE_COMPOUND, CommEvent::TYPE_DECLARATIVE, CommEvent::TYPE_INFORMATIVE],
            $types,
        );
    }

    public function test_its_game_events_include_a_round_type_and_a_non_round_type(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $types = $session->gameEvents()->pluck('type')->all();

        $this->assertNotEmpty(array_intersect($types, GameEvent::ROUND_TYPES));
        $this->assertNotEmpty(array_diff($types, GameEvent::ROUND_TYPES));
    }

    public function test_it_has_at_least_two_dead_air_periods_one_annotated_for_an_uncalled_game_event(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $periods = $session->deadAirPeriods()->get();

        $this->assertGreaterThanOrEqual(2, $periods->count());

        $annotated = $periods->first(fn (DeadAirPeriod $period) => $period->annotations()
            ->where('topic', Annotation::TOPIC_DEAD_AIR)
            ->exists());

        $this->assertNotNull($annotated, 'expected at least one dead-air period to carry an uncalled-game-event annotation');
    }

    public function test_the_analysis_ready_session_is_fully_reviewed(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $this->assertTrue($session->allTimestampsReviewed());
        $this->assertGreaterThan(0, $session->reviewCounts()['total']);
    }

    public function test_it_has_a_human_note_with_a_reply_alongside_a_system_annotation(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $commEventIds = $session->commEventsQuery()->pluck('id');

        $note = Annotation::where('topic', Annotation::TOPIC_NOTE)
            ->whereIn('annotatable_id', $commEventIds)
            ->whereNotNull('user_id')
            ->sole();

        $reply = Annotation::where('topic', Annotation::TOPIC_REPLY)->where('parent_id', $note->id)->sole();

        $this->assertNotNull($reply->user_id);
        $this->assertNotSame($note->user_id, $reply->user_id);

        $this->assertTrue(
            Annotation::whereNull('user_id')->exists(),
            'expected a system-generated annotation to also exist',
        );
    }

    public function test_it_assesses_game_state_alignment_on_the_compound_callout(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->sole();

        $compound = $session->commEventsQuery()->where('communication_type', CommEvent::TYPE_COMPOUND)->sole();

        $alignment = Annotation::where('topic', Annotation::TOPIC_GAME_STATE_ALIGNMENT)
            ->where('annotatable_id', $compound->id)
            ->sole();

        $this->assertNull($alignment->user_id);
        $this->assertNotEmpty($alignment->game_event_ids);
    }

    public function test_running_it_twice_does_not_duplicate_the_demo_sessions(): void
    {
        $this->runDemoSeeder();
        $this->runDemoSeeder();

        $team = $this->thunderbolts();

        $this->assertSame(
            1,
            Session::where('team_id', $team->id)->where('session_name', DemoSessionSeeder::ANALYSIS_READY_SESSION_NAME)->count(),
        );
    }

    public function test_it_creates_a_timeline_ready_session_with_some_reviewed_and_some_unreviewed_timestamps(): void
    {
        $this->runDemoSeeder();

        $session = Session::where('team_id', $this->thunderbolts()->id)
            ->where('status', Session::STATUS_TIMELINE_READY)
            ->sole();

        $this->assertSame(DemoSessionSeeder::TIMELINE_READY_SESSION_NAME, $session->session_name);

        $counts = $session->reviewCounts();

        $this->assertGreaterThan(0, $counts['reviewed']);
        $this->assertGreaterThan($counts['reviewed'], $counts['total']);
    }

    public function test_running_it_twice_does_not_duplicate_the_timeline_ready_session(): void
    {
        $this->runDemoSeeder();
        $this->runDemoSeeder();

        $team = $this->thunderbolts();

        $this->assertSame(
            1,
            Session::where('team_id', $team->id)->where('session_name', DemoSessionSeeder::TIMELINE_READY_SESSION_NAME)->count(),
        );
    }
}
