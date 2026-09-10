<?php

namespace Tests\Concerns;

use App\Models\Annotation;
use App\Models\AodRecord;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Fixture builders for the team dashboard (issue #17): a team with a coach
 * and N players, and analysis-ready sessions carrying per-player communication
 * events, game-state-alignment annotations, game events and dead-air periods.
 */
trait BuildsDashboard
{
    use CreatesTeamsAndSessions;

    /**
     * @return array{0: Team, 1: User, 2: array<int, User>}
     */
    protected function dashboardTeam(int $players = 2): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $roster = [];
        for ($i = 0; $i < $players; $i++) {
            $roster[] = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        }

        return [$team, $coach, $roster];
    }

    /**
     * One analysis-ready session on $team. Every player in $players gets a
     * completed transcript of $windowMs. Pass `comm` per player index in $spec
     * to attach communication events; see addCommEvents() for the spec shape.
     *
     * @param  array<int, User>  $players
     * @param  array<int, array<string, int>>  $spec  keyed by player index
     */
    protected function analysisReadySession(
        Team $team,
        User $coach,
        array $players,
        CarbonInterface $readyAt,
        array $spec = [],
        int $windowMs = 300000,
        int $gameEvents = 0,
        int $deadAir = 0,
    ): Session {
        $session = $this->createSession($team, $coach, Session::STATUS_ANALYSIS_READY);
        $session->forceFill(['analysis_ready_at' => $readyAt])->save();
        $session->timeline()->create();
        $this->addParticipant($session, $coach, 'main_coach');

        foreach ($players as $index => $player) {
            $participant = $this->addParticipant(
                $session,
                $player,
                'player',
                SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            );

            $aod = AodRecord::factory()->for($participant)->create();
            $transcript = Transcript::factory()->for($aod)->completed()->create([
                'audio_duration_ms' => $windowMs,
                'comm_events_detected' => true,
            ]);

            if (isset($spec[$index])) {
                $this->addCommEvents($transcript, $spec[$index]);
            }
        }

        for ($i = 0; $i < $gameEvents; $i++) {
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

        return $session;
    }

    /**
     * Attach communication events to one transcript.
     *
     * Spec keys, all optional and defaulting to 0:
     *   informative / declarative / compound  -- events of that type
     *   redundant                             -- how many of the created events
     *                                            carry is_redundant = true
     *   positive / negative / neutral         -- game_state_alignment
     *                                            annotations at that assessment,
     *                                            one per event, attached to the
     *                                            first events created
     *
     * @param  array<string, int>  $spec
     */
    protected function addCommEvents(Transcript $transcript, array $spec): void
    {
        $events = [];

        foreach ([
            CommEvent::TYPE_INFORMATIVE => $spec['informative'] ?? 0,
            CommEvent::TYPE_DECLARATIVE => $spec['declarative'] ?? 0,
            CommEvent::TYPE_COMPOUND => $spec['compound'] ?? 0,
        ] as $type => $count) {
            for ($i = 0; $i < $count; $i++) {
                $n = count($events);
                $events[] = CommEvent::create([
                    'transcript_id' => $transcript->id,
                    'communication_type' => $type,
                    'is_redundant' => false,
                    'start_ms' => 10000 + $n * 4000,
                    'end_ms' => 11000 + $n * 4000,
                    'content' => "callout {$n}",
                    'padding_ms' => 2000,
                ]);
            }
        }

        foreach (array_slice($events, 0, $spec['redundant'] ?? 0) as $event) {
            $event->update(['is_redundant' => true]);
        }

        $assessments = array_merge(
            array_fill(0, $spec['positive'] ?? 0, Annotation::ASSESSMENT_POSSIBLY_POSITIVE),
            array_fill(0, $spec['negative'] ?? 0, Annotation::ASSESSMENT_POSSIBLY_NEGATIVE),
            array_fill(0, $spec['neutral'] ?? 0, Annotation::ASSESSMENT_NEUTRAL),
        );

        foreach ($assessments as $i => $assessment) {
            $events[$i]->annotations()->create([
                'user_id' => null,
                'topic' => Annotation::TOPIC_GAME_STATE_ALIGNMENT,
                'body' => 'alignment',
                'game_event_ids' => [],
                'assessment' => $assessment,
                'alignment_window_ms' => 5000,
            ]);
        }
    }
}
