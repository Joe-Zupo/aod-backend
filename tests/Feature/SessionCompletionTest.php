<?php

namespace Tests\Feature;

use App\Exceptions\SessionTransitionException;
use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Completion of an in_progress Session: game_events only
 * (docs/adr/0012-per-player-recording-uploads.md moved file delivery to
 * POST /sessions/{session}/recording). The Session moves in_progress ->
 * processing and every recording participant is swept to completed once at
 * least one already-stored AodRecord/VodRecord pair belongs to the same
 * participant.
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
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            ]);
        }
        $this->assertDatabaseHas('session_participants', [
            'user_id' => $coach->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
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

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
    }

    public function test_completion_is_rejected_when_no_one_has_uploaded_anything(): void
    {
        [, $coach, $session] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertStatus(422)
            ->assertJsonPath('message', 'At least one player must provide both an audio and a video recording.');
    }

    public function test_a_participant_with_no_upload_is_still_swept_to_completed(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[0]->id])->create();
        VodRecord::factory()->for($parts[$players[0]->id])->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
    }

    public function test_a_player_who_left_mid_session_with_a_full_pair_still_counts(): void
    {
        [, $coach, $session, $players, $parts] = $this->recordingSession(2);
        AodRecord::factory()->for($parts[$players[1]->id])->create();
        VodRecord::factory()->for($parts[$players[1]->id])->create();
        $session->participants()->where('user_id', $players[1]->id)->update(['left_at' => now()]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        $this->assertDatabaseHas('session_participants', [
            'user_id' => $players[1]->id,
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        ]);
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

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_IN_PROGRESS]);
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
            $this->assertSame('Only an in_progress session can be completed.', $e->getMessage());
        }

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => Session::STATUS_CANCELLED]);
    }

    /**
     * An in_progress session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players, SessionParticipant[] $parts]
     * where $parts is keyed by user id.
     */
    private function recordingSession(int $players = 2, string $status = Session::STATUS_IN_PROGRESS): array
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
            ->assertJsonPath('message', 'Only an in_progress session can be completed.');

        $this->assertDatabaseHas('app_sessions', ['id' => $session->id, 'status' => $status]);
    }
}
