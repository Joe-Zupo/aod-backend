<?php

namespace Tests\Concerns;

use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\User;
use Database\Seeders\Concerns\RecordsPlayerSessions;

/**
 * Builds a `timeline_ready` session carrying a chosen number of each timestamp
 * kind, all unreviewed, for timeline-management tests (ADR 0010).
 */
trait BuildsTimeline
{
    use CreatesTeamsAndSessions, RecordsPlayerSessions;

    /**
     * @return array{0: Team, 1: User, 2: Session, 3: SessionParticipant, 4: Transcript}
     */
    protected function timelineReadySession(int $comm = 0, int $game = 0, int $deadAir = 0, string $status = Session::STATUS_TIMELINE_READY): array
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

        $transcript = $this->recordCompletedTranscript($player);

        for ($i = 0; $i < $comm; $i++) {
            CommEvent::create([
                'transcript_id' => $transcript->id,
                'communication_type' => CommEvent::TYPE_DECLARATIVE,
                'is_redundant' => false,
                'start_ms' => 10000 + $i * 5000,
                'end_ms' => 11000 + $i * 5000,
                'content' => "callout {$i}",
                'padding_ms' => 2000,
            ]);
        }

        for ($i = 0; $i < $game; $i++) {
            GameEvent::factory()->for($session)->create([
                'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 20000 + $i * 5000,
            ]);
        }

        for ($i = 0; $i < $deadAir; $i++) {
            DeadAirPeriod::create([
                'session_id' => $session->id,
                'start_ms' => 100000 + $i * 20000,
                'end_ms' => 112000 + $i * 20000,
                'dead_air_threshold_ms' => 5000,
            ]);
        }

        return [$team, $coach, $session, $player, $transcript];
    }

    protected function playerOn(Session $session): User
    {
        return $this->makeAndAttachMember($session->team, 'player', 'Player', User::whereKey($session->created_by)->first());
    }
}
