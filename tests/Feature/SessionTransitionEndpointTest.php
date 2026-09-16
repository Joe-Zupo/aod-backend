<?php

namespace Tests\Feature;

use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsTimeline;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * POST /sessions/{session}/transitions is the one endpoint for every
 * coach-driven, bodiless state move (cancel, re-analyze, analysis-ready,
 * reopen-review). The individual guards and side effects are covered by
 * SessionControlTest / SessionReanalyzeTest / AnalysisReadyTransitionTest; this
 * covers the `to` vocabulary and the shared policy (ADR 0010).
 */
class SessionTransitionEndpointTest extends TestCase
{
    use BuildsTimeline, CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_an_unknown_target_status_is_a_422(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'ready_for_review'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['to']]]);
    }

    public function test_to_in_progress_points_at_the_start_endpoint(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Use POST /sessions/{session}/start to begin recording.');
    }

    public function test_a_player_cannot_transition_a_session(): void
    {
        [, , $session] = $this->timelineReadySession();
        $player = $this->playerOn($session);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'analysis_ready'])
            ->assertForbidden();
    }

    public function test_one_endpoint_drives_reopen_from_analysis_ready(): void
    {
        [, $coach, $session] = $this->timelineReadySession();
        $session->update(['status' => Session::STATUS_ANALYSIS_READY]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'timeline_ready'])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_TIMELINE_READY);
    }

    public function test_a_coach_stops_recording_and_the_session_returns_to_the_lobby(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $coachRow = $this->addParticipant($session, $coach, 'main_coach');
        foreach ([$one, $two] as $player) {
            $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        }

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'queuing');

        $this->assertSame('queuing', $session->fresh()->status);

        foreach ([$one, $two] as $player) {
            $this->assertDatabaseHas('session_participants', [
                'session_id' => $session->id,
                'user_id' => $player->id,
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
            ]);
        }

        // A Coach has nothing to consent to, so the reset returns them to the
        // status their role starts at, which is where they already were.
        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_READY,
            $coachRow->fresh()->participant_status,
        );
    }

    public function test_stopping_recording_is_refused_when_the_session_is_not_in_progress(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertStatus(422);
    }

    public function test_a_player_cannot_stop_the_teams_recording(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertForbidden();

        $this->assertSame('in_progress', $session->fresh()->status);
    }

    public function test_the_team_consents_again_and_the_coach_restarts_the_session(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertOk();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk();

        $this->assertSame('in_progress', $session->fresh()->status);

        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        ]);
    }

    public function test_the_coach_stopping_recording_destroys_every_uploaded_recording(): void
    {
        Storage::fake('local');

        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $this->addParticipant($session, $coach, 'main_coach');
        foreach ([$one, $two] as $player) {
            $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

            $this->actingAs($player, 'sanctum')
                ->post("/api/sessions/{$session->id}/recording", [
                    'audio' => UploadedFile::fake()->create('a.m4a', 10, 'audio/mp4'),
                    'video' => UploadedFile::fake()->create('v.mp4', 10, 'video/mp4'),
                ])
                ->assertOk();
        }

        $paths = AodRecord::pluck('path')->merge(VodRecord::pluck('path'))->all();
        $this->assertCount(4, $paths);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertOk();

        // The Coach's stop abandons the run, and an abandoned run keeps nothing
        // (docs/adr/0013-recording-control-and-departure.md).
        $this->assertSame(0, AodRecord::count());
        $this->assertSame(0, VodRecord::count());

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }
}
