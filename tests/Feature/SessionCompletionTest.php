<?php

namespace Tests\Feature;

use App\Exceptions\SessionTransitionException;
use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Completion of a delivering Session: game_events only
 * (docs/adr/0012-per-player-recording-uploads.md moved file delivery to
 * POST /sessions/{session}/recording). The Session moves delivering ->
 * processing and every active participant is swept to ending once at least
 * one already-stored AodRecord/VodRecord pair belongs to a participant still
 * recording (docs/adr/0015-end-of-run-and-end-of-participation.md).
 */
class SessionCompletionTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake('local');
        Queue::fake();
    }

    public function test_active_coach_completes_a_session_once_one_participant_has_a_full_pair(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_PROCESSING]);

        foreach ($players as $player) {
            $this->assertDatabaseHas('session_participants', [
                'user_id' => $player->id,
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING,
            ]);
        }
        $this->assertDatabaseHas('session_participants', [
            'user_id' => $coach->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING,
        ]);
    }

    public function test_completion_is_rejected_when_no_participant_has_a_full_pair(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[1]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one player must provide both an audio and a video recording.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_DELIVERING]);
    }

    public function test_completion_is_rejected_when_no_one_has_uploaded_anything(): void
    {
        [, $coach, $session] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one player must provide both an audio and a video recording.');
    }

    public function test_a_participant_with_no_upload_is_still_swept_to_ending(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING,
        ]);
    }

    public function test_a_departed_players_stored_pair_does_not_satisfy_the_completion_guard(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[1]->id])->create();
        VodRecord::factory()->for($parts[$players[1]->id])->create();
        $session->participants()->where('user_id', $players[1]->id)->update(['left_at' => now()]);

        // Completion reads the participants who are still in the session, so a
        // departed row's pair counts for nothing: the run has no delivered
        // recording from anyone who is still here
        // (docs/adr/0014-participation-lifecycle.md).
        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one player must provide both an audio and a video recording.');
    }

    public function test_a_departed_players_stored_audio_is_not_transcribed(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();
        $departedAod = AodRecord::factory()->for($parts[$players[1]->id])->create();
        VodRecord::factory()->for($parts[$players[1]->id])->create();
        $session->participants()->where('user_id', $players[1]->id)->update(['left_at' => now()]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        // One transcript, for the player who was still in the session.
        $this->assertDatabaseMissing('transcripts', ['aod_record_id' => $departedAod->id]);
        $this->assertSame(1, Transcript::count());
    }

    public function test_completing_an_in_progress_session_names_the_missing_step(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(1, Session::STATUS_IN_PROGRESS);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Finish the run before completing it: move the session to delivering first.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_completing_a_queuing_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_QUEUING);
    }

    public function test_completing_an_already_processing_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_PROCESSING);
    }

    public function test_completing_a_cancelled_session_is_rejected(): void
    {
        $this->assertWrongStatusRejected(Session::STATUS_CANCELLED);
    }

    public function test_an_assistant_coach_who_did_not_create_the_session_can_complete_it(): void
    {
        [$team, $mainCoach, $session, $players, $parts] = $this->recordingSession(1);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);

        $this->actingAs($assistant, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);
    }

    public function test_a_player_cannot_complete_a_session(): void
    {
        [, , $session, $players] = $this->recordingSession(1);

        $this->actingAs($players[0], 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertForbidden();

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_DELIVERING]);
    }

    public function test_a_coach_of_another_team_gets_404_completing_this_teams_session(): void
    {
        [, , $session] = $this->recordingSession(1);

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertNotFound();
    }

    public function test_completing_a_session_requires_authentication(): void
    {
        [, , $session] = $this->recordingSession(1);

        $this->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertUnauthorized();
    }

    public function test_complete_decides_on_the_stored_status_not_the_loaded_one(): void
    {
        [, , $session, $players, $parts] = $this->recordingSession(1);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $stale = Session::find($session->id);
        Session::whereKey($session->id)->update(['status' => Session::STATUS_CANCELLED]);

        try {
            $stale->complete(json_decode($this->stubGameEvents(), true));
            $this->fail('Expected complete() to reject a session cancelled since it was loaded.');
        } catch (SessionTransitionException $e) {
            $this->assertSame('Only a delivering session can be completed.', $e->getMessage());
        }

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_CANCELLED]);
    }

    /**
     * A delivering session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players, SessionParticipant[] $parts]
     * where $parts is keyed by user id.
     */
    private function recordingSession(int $players = 2, string $status = Session::STATUS_DELIVERING): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, $status);
        $this->addParticipant($session, $coach, 'main_coach');

        $users = [];
        $parts = [];
        for ($i = 0; $i < $players; $i++) {
            $user = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
            $parts[$user->id] = $this->addParticipant($session, $user, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
            $users[] = $user;
        }

        return [$team, $coach, $session, $users, $parts];
    }

    private function assertWrongStatusRejected(string $status): void
    {
        [, $coach, $session] = $this->recordingSession(1, $status);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a delivering session can be completed.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => $status]);
    }

    public function test_completion_ends_the_coach_too(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(1);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        // `ending` means the session is over for this participant while its
        // analysis runs, and it is over for the Coach as much as for the
        // players who recorded (ADR 0014, ADR 0015).
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $coach->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING,
        ]);
    }

    public function test_completion_ends_a_player_who_stopped_early(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2, Session::STATUS_IN_PROGRESS);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($players[1], 'sanctum')
            ->postJson("/api/sessions/{$session->id}/stop-recording")
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'delivering'])
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        foreach ($players as $player) {
            $this->assertDatabaseHas('session_participants', [
                'session_id' => $session->id,
                'user_id' => $player->id,
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_ENDING,
            ]);
        }
    }

    public function test_completion_leaves_a_departed_participant_alone(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($players[1], 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        // They were not in the session when it ended, so it did not end for
        // them; `left_at` already says what happened.
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $session->id,
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        ]);
    }
}
