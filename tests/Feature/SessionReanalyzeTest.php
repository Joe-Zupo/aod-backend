<?php

namespace Tests\Feature;

use App\Jobs\DetectCommEvents;
use App\Models\AodRecord;
use App\Models\CommEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\TeamSettings;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * POST /sessions/{id}/reanalyze: rebuild a timeline_ready session's timeline
 * from its stored recordings under the team's current settings, for a coach
 * who re-tuned keywords or padding after the first analysis.
 */
class SessionReanalyzeTest extends TestCase
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
     * A timeline_ready session with the creating Coach and one completed-status
     * player who has an AOD and a completed, already-detected transcript.
     *
     * @return array{0: Team, 1: User, 2: Session, 3: SessionParticipant, 4: Transcript}
     */
    private function readySession(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);
        $session->timeline()->create();
        $this->addParticipant($session, $coach, 'main_coach');

        $player = $this->addParticipant(
            $session,
            $this->makeAndAttachMember($team, 'player', 'Player', $coach),
            'player',
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        );

        $aod = AodRecord::factory()->for($player)->create();
        $transcript = Transcript::factory()->for($aod)->completed()->create([
            'audio_duration_ms' => 60000,
            'comm_events_detected' => true,
        ]);

        return [$team, $coach, $session, $player, $transcript];
    }

    /**
     * @param  array<int, array{0: string, 1: int, 2: int}>  $words
     */
    private function addWords(Transcript $transcript, array $words): void
    {
        foreach ($words as $i => [$word, $start, $end]) {
            $transcript->words()->create([
                'position' => $i,
                'word' => $word,
                'start_ms' => $start,
                'end_ms' => $end,
                'confidence' => 0.9,
            ]);
        }
    }

    public function test_reanalyze_rebuilds_comm_events_from_the_current_words_and_keywords(): void
    {
        [, $coach, $session, , $transcript] = $this->readySession();
        $this->addWords($transcript, [
            ['rotating', 1000, 1500],
            ['now', 1600, 1900],
        ]);

        // A stale event from a prior analysis that the current words don't support.
        $stale = CommEvent::create([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_INFORMATIVE,
            'is_redundant' => false,
            'start_ms' => 90000,
            'end_ms' => 90500,
            'content' => 'stale',
            'padding_ms' => 2000,
        ]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertStatus(202)
            ->assertJsonPath('data.session.status', Session::STATUS_TIMELINE_READY);

        $this->assertDatabaseMissing('comm_events', ['id' => $stale->id]);

        $event = CommEvent::where('transcript_id', $transcript->id)->sole();
        $this->assertSame('rotating', $event->calloutDetections()->sole()->normalized_keyword);
    }

    public function test_reanalyze_drops_events_for_a_keyword_the_coach_removed(): void
    {
        [$team, $coach, $session, , $transcript] = $this->readySession();
        $this->addWords($transcript, [['rotating', 1000, 1500]]);

        (new DetectCommEvents($transcript))->handle();
        $this->assertSame(1, CommEvent::where('transcript_id', $transcript->id)->count());

        $team->ensureSettings()
            ->get(TeamSettings::SETTING_DECLARATIVE_KEYWORDS)
            ->keywords()
            ->where('keyword', 'rotating')
            ->delete();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertStatus(202);

        $this->assertSame(0, CommEvent::where('transcript_id', $transcript->id)->count());
        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }

    public function test_reanalyze_moves_back_to_processing_and_redispatches_detection_per_completed_transcript(): void
    {
        Bus::fake();

        [$team, $coach, $session, , $completed] = $this->readySession();

        // A second recording whose transcription failed: it must not be re-dispatched.
        $other = $this->addParticipant(
            $session,
            $this->makeAndAttachMember($team, 'player', 'Player', $coach),
            'player',
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        );
        Transcript::factory()->for(AodRecord::factory()->for($other)->create())->failed()->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertStatus(202)
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
        $this->assertFalse((bool) $completed->fresh()->comm_events_detected);
        Bus::assertDispatched(DetectCommEvents::class, 1);
    }

    public function test_reanalyze_is_forbidden_for_a_player(): void
    {
        [$team, $coach, $session] = $this->readySession();
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertForbidden();
    }

    public function test_reanalyze_is_not_found_for_a_non_member(): void
    {
        [, , $session] = $this->readySession();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertNotFound();
    }

    public function test_reanalyze_is_rejected_unless_the_timeline_is_ready(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        foreach ([Session::STATUS_PROCESSING, Session::STATUS_QUEUING, Session::STATUS_IN_PROGRESS, Session::STATUS_CANCELLED] as $status) {
            $session = $this->createSession($team, $coach, $status);

            $this->actingAs($coach, 'sanctum')
                ->postJson("/api/sessions/{$session->id}/reanalyze")
                ->assertStatus(422);
        }
    }

    public function test_reanalyze_is_rejected_when_no_recording_was_transcribed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);
        $session->timeline()->create();

        $player = $this->addParticipant(
            $session,
            $this->makeAndAttachMember($team, 'player', 'Player', $coach),
            'player',
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        );
        Transcript::factory()->for(AodRecord::factory()->for($player)->create())->failed()->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/reanalyze")
            ->assertStatus(422);

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }
}
