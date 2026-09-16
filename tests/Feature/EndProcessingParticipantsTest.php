<?php

namespace Tests\Feature;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use App\Support\EndProcessingParticipants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-shot correction behind ADR 0015's migration: participants of a
 * session that was already `processing` were swept straight to `completed`,
 * and belong at `ending` until its timeline is ready.
 */
class EndProcessingParticipantsTest extends TestCase
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
            'participant_role' => 'player',
            'participant_status' => $participantStatus,
            'left_at' => $leftAt,
        ]);
    }

    public function test_it_moves_an_active_participant_of_a_processing_session_to_ending(): void
    {
        $player = $this->participantOn(Session::STATUS_PROCESSING, SessionParticipant::PARTICIPANT_STATUS_COMPLETED);

        $this->assertSame(1, (new EndProcessingParticipants)());

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_ENDING, $player->fresh()->participant_status);
    }

    public function test_it_leaves_a_session_whose_timeline_is_ready_alone(): void
    {
        $player = $this->participantOn(Session::STATUS_TIMELINE_READY, SessionParticipant::PARTICIPANT_STATUS_COMPLETED);

        (new EndProcessingParticipants)();

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_COMPLETED, $player->fresh()->participant_status);
    }

    public function test_it_leaves_a_departed_participant_alone(): void
    {
        $player = $this->participantOn(
            Session::STATUS_PROCESSING,
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            '2026-09-16 12:00:00',
        );

        (new EndProcessingParticipants)();

        $this->assertSame(SessionParticipant::PARTICIPANT_STATUS_COMPLETED, $player->fresh()->participant_status);
    }
}
