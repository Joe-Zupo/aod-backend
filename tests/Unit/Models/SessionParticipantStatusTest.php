<?php

namespace Tests\Unit\Models;

use App\Exceptions\SessionTransitionException;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-session participant status machine: how a row's participant_status
 * is set at join time and advanced forward through consent and the start
 * sweep.
 */
class SessionParticipantStatusTest extends TestCase
{
    use RefreshDatabase;

    private function team(): Team
    {
        return Team::factory()->create();
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['member_role' => $role, 'status' => 'active', 'joined_at' => now()]);

        return $user;
    }

    public function test_a_player_joins_needing_consent(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();

        $participant = $session->joinOrRejoin($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT, $participant->participant_status);
    }

    public function test_a_main_coach_joins_ready(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $session = Session::factory()->for($team)->create();

        $participant = $session->joinOrRejoin($coach);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->participant_status);
    }

    public function test_an_assistant_coach_joins_ready(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'assistant_coach');
        $session = Session::factory()->for($team)->create();

        $participant = $session->joinOrRejoin($coach);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->participant_status);
    }

    public function test_rejoining_after_leaving_resets_a_consented_player_to_needs_consent(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($player);
        $participant->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);
        $participant->leave();

        $rejoined = $session->joinOrRejoin($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT, $rejoined->fresh()->participant_status);
    }

    public function test_joining_an_already_active_participant_leaves_status_untouched(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($player);
        $participant->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);

        $session->joinOrRejoin($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
    }

    public function test_advance_status_to_moves_one_step_forward_and_persists(): void
    {
        $participant = SessionParticipant::factory()->needsConsent()->create();

        $participant->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
    }

    public function test_advance_status_to_the_current_status_is_a_silent_no_op(): void
    {
        $participant = SessionParticipant::factory()->create([
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
        ]);

        $participant->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
    }

    public function test_advance_status_to_rejects_skipping_a_step(): void
    {
        $participant = SessionParticipant::factory()->needsConsent()->create();

        $this->expectException(SessionTransitionException::class);

        $participant->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_RECORDING);
    }

    public function test_advance_status_to_rejects_stepping_backward(): void
    {
        $participant = SessionParticipant::factory()->create([
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        ]);

        $this->expectException(SessionTransitionException::class);

        $participant->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_READY);
    }

    public function test_record_consent_moves_the_callers_row_to_ready(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($player);

        $returned = $session->recordConsent($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
        $this->assertTrue($returned->is($participant));
    }

    public function test_record_consent_is_idempotent_when_already_ready(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $participant = $session->joinOrRejoin($player);
        $participant->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);

        $session->recordConsent($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
    }

    public function test_record_consent_rejects_a_coach(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $session = Session::factory()->for($team)->create();
        $session->joinOrRejoin($coach);

        $this->expectExceptionMessage('A coach has nothing to consent to.');

        $session->recordConsent($coach);
    }

    public function test_record_consent_rejects_a_row_already_past_ready(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create(['status' => Session::STATUS_IN_PROGRESS]);
        $participant = $session->joinOrRejoin($player);
        $participant->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING]);

        $this->expectExceptionMessage('Consent can no longer be recorded for this session.');

        $session->recordConsent($player);
    }

    public function test_record_consent_rejects_a_non_participant(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();

        $this->expectExceptionMessage('You are not in this session.');

        $session->recordConsent($player);
    }

    public function test_record_consent_rejects_a_completed_session(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $session->joinOrRejoin($player);
        $session->update(['status' => Session::STATUS_COMPLETED]);

        $this->expectExceptionMessage('Consent can no longer be recorded for this session.');

        $session->recordConsent($player);
    }

    public function test_record_consent_during_an_in_progress_session_only_moves_to_ready(): void
    {
        $team = $this->team();
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create(['status' => Session::STATUS_IN_PROGRESS]);
        $participant = $session->joinOrRejoin($player);

        $session->recordConsent($player);

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $participant->fresh()->participant_status);
    }

    public function test_start_moves_ready_players_to_recording(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $one = $this->member($team, 'player');
        $two = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $coachRow = $session->joinOrRejoin($coach);
        $oneRow = $session->joinOrRejoin($one);
        $twoRow = $session->joinOrRejoin($two);
        $oneRow->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);
        $twoRow->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);

        $session->start();

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_RECORDING, $oneRow->fresh()->participant_status);
        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_RECORDING, $twoRow->fresh()->participant_status);
    }

    public function test_start_leaves_coach_rows_at_ready(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $coachRow = $session->joinOrRejoin($coach);
        $playerRow = $session->joinOrRejoin($player);
        $playerRow->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);

        $session->start();

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_READY, $coachRow->fresh()->participant_status);
    }

    public function test_start_is_rejected_when_a_player_has_not_consented(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $player = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        $this->expectExceptionMessage('Every player must consent before the session can start.');

        $session->start();
    }

    public function test_start_ignores_a_player_who_has_left_without_consenting(): void
    {
        $team = $this->team();
        $coach = $this->member($team, 'main_coach');
        $present = $this->member($team, 'player');
        $gone = $this->member($team, 'player');
        $session = Session::factory()->for($team)->create();
        $session->joinOrRejoin($coach);
        $presentRow = $session->joinOrRejoin($present);
        $presentRow->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY]);
        $goneRow = $session->joinOrRejoin($gone);
        $goneRow->update(['left_at' => now()]);

        $session->start();

        $this->assertSame(Session::STATUS_IN_PROGRESS, $session->fresh()->status);
        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_RECORDING, $presentRow->fresh()->participant_status);
        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT, $goneRow->fresh()->participant_status);
    }
}
