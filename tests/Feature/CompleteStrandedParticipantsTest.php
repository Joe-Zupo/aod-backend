<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use App\Support\CompleteStrandedParticipants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-shot correction behind the ADR 0014 migration: sessions that had
 * already finished when `participant_status` was redefined carry participants
 * frozen at whatever they were doing when the run ended.
 */
class CompleteStrandedParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function participantOn(string $sessionStatus, string $participantStatus, ?string $leftAt = null): SessionParticipant
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $session = Session::factory()->for($team)->create([
            'created_by' => $user->id,
            'status' => $sessionStatus,
        ]);

        return SessionParticipant::factory()->for($session)->create([
            'user_id' => $user->id,
            'participant_role' => 'main_coach',
            'participant_status' => $participantStatus,
            'left_at' => $leftAt,
        ]);
    }

    public function test_it_completes_a_coach_stranded_on_a_finished_session(): void
    {
        $coach = $this->participantOn(
            Session::STATUS_ANALYSIS_READY,
            SessionParticipant::PARTICIPANT_STATUS_READY,
        );

        (new CompleteStrandedParticipants)();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            $coach->fresh()->participant_status,
        );
    }

    public function test_it_completes_a_player_stranded_mid_lifecycle(): void
    {
        $player = $this->participantOn(
            Session::STATUS_PROCESSING,
            SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
        );

        (new CompleteStrandedParticipants)();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            $player->fresh()->participant_status,
        );
    }

    public function test_it_leaves_a_session_still_under_way_alone(): void
    {
        $player = $this->participantOn(
            Session::STATUS_IN_PROGRESS,
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        );

        (new CompleteStrandedParticipants)();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            $player->fresh()->participant_status,
        );
    }

    public function test_it_leaves_a_departed_participant_alone(): void
    {
        // `left_at` already says their participation ended, and they were not
        // in the session when it finished.
        $player = $this->participantOn(
            Session::STATUS_TIMELINE_READY,
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            now()->toDateTimeString(),
        );

        (new CompleteStrandedParticipants)();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            $player->fresh()->participant_status,
        );
    }

    public function test_it_leaves_a_cancelled_sessions_participants_alone(): void
    {
        $player = $this->participantOn(
            Session::STATUS_CANCELLED,
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        );

        (new CompleteStrandedParticipants)();

        $this->assertSame(
            SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            $player->fresh()->participant_status,
        );
    }
}
