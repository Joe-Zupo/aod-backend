<?php

namespace Database\Seeders;

use App\Models\Annotation;
use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\User;
use App\Support\DeadAirDetector;
use App\Support\GameStateAlignmentAssessor;
use Database\Seeders\Concerns\RecordsPlayerSessions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds three fully analysed (`analysis_ready`) demo sessions on the
 * Thunderbolts team, each richly timestamped so a fresh checkout can develop
 * against the Review Board without a live pipeline, plus one `timeline_ready`
 * session to exercise coach-only gating (issue #20). Requires TeamSeeder to
 * have already run. Skips entirely if the first analysis-ready demo session
 * already exists, so re-running `db:seed` is a no-op.
 */
class DemoSessionSeeder extends Seeder
{
    use RecordsPlayerSessions;

    public const ANALYSIS_READY_SESSION_NAME = 'Demo Analysis Ready';

    public const ANALYSIS_READY_SESSION_COUNT = 3;

    public const TIMELINE_READY_SESSION_NAME = 'Demo Timeline Ready';

    public static function analysisReadySessionName(int $n): string
    {
        return self::ANALYSIS_READY_SESSION_NAME." {$n}";
    }

    /**
     * Matches Team::ensureSettings()'s default dead_air_threshold /
     * game_alignment_window, since the Thunderbolts team's settings are
     * seeded with those defaults and never edited here.
     */
    private const DEAD_AIR_THRESHOLD_MS = 5000;

    private const GAME_ALIGNMENT_WINDOW_MS = 5000;

    public function run(): void
    {
        $team = Team::where('team_name', 'Thunderbolts')->firstOrFail();

        if (Session::where('team_id', $team->id)->where('session_name', self::analysisReadySessionName(1))->exists()) {
            return;
        }

        DB::transaction(function () use ($team): void {
            for ($n = 1; $n <= self::ANALYSIS_READY_SESSION_COUNT; $n++) {
                $this->seedAnalysisReadySession($team, self::analysisReadySessionName($n));
            }

            $this->seedTimelineReadySession($team);
        });
    }

    /**
     * A second, minimal session under review: one recorded player, a couple of
     * plain comm/game timestamps and one dead-air period with no interior
     * event, split between reviewed and unreviewed so `timeline_ready`'s
     * coach-only gating has something real to exercise. Unlike the
     * analysis-ready session, it doesn't need its own compound callout or
     * uncalled-event dead air — that richness lives on the other session.
     */
    private function seedTimelineReadySession(Team $team): void
    {
        $mainCoach = User::where('username', 'maincoach')->firstOrFail();
        $player = User::where('username', 'player1')->firstOrFail();

        $session = Session::create([
            'team_id' => $team->id,
            'created_by' => $mainCoach->id,
            'session_name' => self::TIMELINE_READY_SESSION_NAME,
            'status' => Session::STATUS_TIMELINE_READY,
        ]);
        $session->timeline()->create();

        SessionParticipant::create([
            'session_id' => $session->id,
            'user_id' => $mainCoach->id,
            'participant_role' => 'main_coach',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
            'joined_at' => now(),
        ]);

        $participant = SessionParticipant::create([
            'session_id' => $session->id,
            'user_id' => $player->id,
            'participant_role' => 'player',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
            'joined_at' => now(),
        ]);
        $transcript = $this->recordCompletedTranscript($participant, 200000);

        $reviewedComm = CommEvent::create([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_INFORMATIVE,
            'is_redundant' => false,
            'start_ms' => 50000,
            'end_ms' => 51000,
            'content' => 'Two rotating mid',
            'padding_ms' => 2000,
        ]);
        $reviewedComm->markReviewed($mainCoach);

        CommEvent::create([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_DECLARATIVE,
            'is_redundant' => false,
            'start_ms' => 5000,
            'end_ms' => 6000,
            'content' => 'One holding A',
            'padding_ms' => 2000,
        ]);

        $reviewedGameEvent = GameEvent::create([
            'session_id' => $session->id, 'source' => 'manual',
            'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 80000, 'round_number' => 3,
        ]);
        $reviewedGameEvent->markReviewed($mainCoach);

        GameEvent::create([
            'session_id' => $session->id, 'source' => 'manual',
            'type' => 'round_win', 'side' => null, 'match_time_ms' => 2000, 'round_number' => 1,
        ]);

        DeadAirPeriod::create([
            'session_id' => $session->id,
            'start_ms' => 6000,
            'end_ms' => 50000,
            'dead_air_threshold_ms' => self::DEAD_AIR_THRESHOLD_MS,
        ]);
    }

    private function seedAnalysisReadySession(Team $team, string $sessionName): void
    {
        $mainCoach = User::where('username', 'maincoach')->firstOrFail();

        $session = Session::create([
            'team_id' => $team->id,
            'created_by' => $mainCoach->id,
            'session_name' => $sessionName,
            'status' => Session::STATUS_ANALYSIS_READY,
        ]);
        $session->timeline()->create();

        SessionParticipant::create([
            'session_id' => $session->id,
            'user_id' => $mainCoach->id,
            'participant_role' => 'main_coach',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
            'joined_at' => now(),
        ]);

        $transcripts = [];

        for ($i = 1; $i <= 5; $i++) {
            $player = User::where('username', "player{$i}")->firstOrFail();

            $participant = SessionParticipant::create([
                'session_id' => $session->id,
                'user_id' => $player->id,
                'participant_role' => 'player',
                'participant_status' => SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
                'joined_at' => now(),
            ]);

            $transcripts[] = $this->recordCompletedTranscript($participant, 320000);
        }

        $commEvents = $this->seedCommunicationEvents($transcripts);
        $this->markAllReviewed($commEvents, $mainCoach);

        $gameEvents = $this->seedGameEvents($session);
        $this->markAllReviewed($gameEvents, $mainCoach);

        $this->seedDeadAirPeriods($session, $commEvents, $gameEvents, $mainCoach);
        $this->seedGameStateAlignment($commEvents[2], $gameEvents, self::GAME_ALIGNMENT_WINDOW_MS);

        $assistantCoach = User::where('username', 'assistantcoach')->firstOrFail();
        $this->seedHumanNoteWithReply($commEvents[0], $mainCoach, $assistantCoach);
    }

    /**
     * Assesses one callout's game-state alignment via the real
     * `GameStateAlignmentAssessor`, exactly like `AssessGameStateAlignment`
     * would, using the callout's known keywords directly rather than real
     * `CalloutDetection` rows (ADR 0008).
     *
     * @param  list<GameEvent>  $gameEvents
     */
    private function seedGameStateAlignment(CommEvent $commEvent, array $gameEvents, int $windowMs): void
    {
        $result = GameStateAlignmentAssessor::assess(
            $commEvent->start_ms,
            $commEvent->end_ms,
            ['planted', 'pushing'],
            $this->gameEventPayload($gameEvents),
            $windowMs,
        );

        if ($result === null) {
            return;
        }

        $commEvent->annotations()->create([
            'user_id' => null,
            'topic' => Annotation::TOPIC_GAME_STATE_ALIGNMENT,
            'assessment' => $result['assessment'],
            'body' => $result['body'],
            'game_event_ids' => $result['game_event_ids'],
            'alignment_window_ms' => $windowMs,
        ]);
    }

    /**
     * @param  list<GameEvent>  $gameEvents
     * @return list<array{id: int, type: string, side: ?string, match_time_ms: int, note: ?string, raw: ?array<string, mixed>}>
     */
    private function gameEventPayload(array $gameEvents): array
    {
        return array_map(fn (GameEvent $event) => [
            'id' => $event->id,
            'type' => $event->type,
            'side' => $event->side,
            'match_time_ms' => (int) $event->match_time_ms,
            'note' => $event->note,
            'raw' => $event->raw,
        ], $gameEvents);
    }

    /**
     * A coach's hand-authored note on a callout with one reply from another
     * coach, so the demo data carries a human annotation alongside the
     * system-generated ones (dead-air, game-state alignment) (ADR 0010).
     */
    private function seedHumanNoteWithReply(CommEvent $commEvent, User $author, User $replier): void
    {
        $note = $commEvent->annotations()->create([
            'user_id' => $author->id,
            'topic' => Annotation::TOPIC_NOTE,
            'assessment' => null,
            'body' => 'Good read on the site, but we were a step slow to trade. Tighten up entry timing here.',
            'game_event_ids' => [],
            'alignment_window_ms' => null,
        ]);

        $note->replies()->create([
            'annotatable_type' => $note->annotatable_type,
            'annotatable_id' => $note->annotatable_id,
            'user_id' => $replier->id,
            'topic' => Annotation::TOPIC_REPLY,
            'assessment' => null,
            'body' => "Agreed, I'll flag it for the next review session.",
            'game_event_ids' => [],
            'alignment_window_ms' => null,
        ]);
    }

    /**
     * @return list<GameEvent>
     */
    private function seedGameEvents(Session $session): array
    {
        return [
            GameEvent::create([
                'session_id' => $session->id, 'source' => 'manual',
                'type' => 'round_win', 'side' => null, 'match_time_ms' => 1000, 'round_number' => 1,
            ]),
            GameEvent::create([
                'session_id' => $session->id, 'source' => 'manual',
                'type' => 'kill', 'side' => 'ally', 'match_time_ms' => 25000, 'round_number' => 2,
            ]),
            GameEvent::create([
                'session_id' => $session->id, 'source' => 'manual',
                'type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 71500, 'round_number' => 4,
            ]),
        ];
    }

    /**
     * Derives the session's dead-air periods from the real comm-event spans via
     * the pure `DeadAirDetector`, exactly like `DetectDeadAir` would, and
     * annotates any period with a game event strictly inside it (ADR 0009) —
     * without going through the queued detection pipeline.
     *
     * @param  list<CommEvent>  $commEvents
     * @param  list<GameEvent>  $gameEvents
     */
    private function seedDeadAirPeriods(Session $session, array $commEvents, array $gameEvents, User $coach): void
    {
        $thresholdMs = self::DEAD_AIR_THRESHOLD_MS;
        $windowMs = $session->windowMs();

        $spans = array_map(fn (CommEvent $ce) => [$ce->start_ms, $ce->end_ms], $commEvents);
        $periods = DeadAirDetector::periods($spans, $windowMs, $thresholdMs);

        $gameEventPayload = array_map(fn (GameEvent $e) => [
            'id' => $e->id, 'type' => $e->type, 'side' => $e->side,
            'match_time_ms' => (int) $e->match_time_ms, 'note' => $e->note, 'raw' => $e->raw,
        ], $gameEvents);

        foreach ($periods as $span) {
            $period = DeadAirPeriod::create([
                'session_id' => $session->id,
                'start_ms' => $span['start_ms'],
                'end_ms' => $span['end_ms'],
                'dead_air_threshold_ms' => $thresholdMs,
            ]);
            $period->markReviewed($coach);

            $interior = array_values(array_filter(
                $gameEventPayload,
                fn (array $event) => $event['match_time_ms'] > $span['start_ms'] && $event['match_time_ms'] < $span['end_ms'],
            ));

            if ($interior === []) {
                continue;
            }

            $period->annotations()->create([
                'user_id' => null,
                'topic' => Annotation::TOPIC_DEAD_AIR,
                'assessment' => null,
                'body' => DeadAirDetector::body($span['start_ms'], $span['end_ms'], $interior),
                'game_event_ids' => array_map(fn (array $event) => $event['id'], $interior),
                'alignment_window_ms' => null,
            ]);
        }
    }

    /**
     * @param  list<CommEvent|GameEvent|DeadAirPeriod>  $rows
     */
    private function markAllReviewed(array $rows, User $coach): void
    {
        foreach ($rows as $row) {
            $row->markReviewed($coach);
        }
    }

    /**
     * @param  list<Transcript>  $transcripts  one per player, in player order
     * @return list<CommEvent>
     */
    private function seedCommunicationEvents(array $transcripts): array
    {
        $specs = [
            ['player' => 0, 'type' => CommEvent::TYPE_INFORMATIVE, 'start' => 10000, 'end' => 12000, 'content' => 'Two on site, need trade'],
            ['player' => 1, 'type' => CommEvent::TYPE_DECLARATIVE, 'start' => 40000, 'end' => 42000, 'content' => 'Rotating B, cover me'],
            ['player' => 2, 'type' => CommEvent::TYPE_COMPOUND, 'start' => 70000, 'end' => 73000, 'content' => 'Spike planted A, pushing now'],
            ['player' => 3, 'type' => CommEvent::TYPE_INFORMATIVE, 'start' => 150000, 'end' => 152000, 'content' => 'Enemy spotted mid, low HP'],
            ['player' => 4, 'type' => CommEvent::TYPE_DECLARATIVE, 'start' => 300000, 'end' => 302000, 'content' => 'Holding heaven, watching flank'],
        ];

        return array_map(fn (array $spec) => CommEvent::create([
            'transcript_id' => $transcripts[$spec['player']]->id,
            'communication_type' => $spec['type'],
            'is_redundant' => false,
            'start_ms' => $spec['start'],
            'end_ms' => $spec['end'],
            'content' => $spec['content'],
            'padding_ms' => 2000,
        ]), $specs);
    }
}
