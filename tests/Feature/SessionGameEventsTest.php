<?php

namespace Tests\Feature;

use App\Models\GameEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * The manual game-event payload on session completion (docs/adr/0007). A
 * `game_events` JSON string travels in the multipart body beside `players[]`;
 * every element is stored as a `game_events` row against the session inside the
 * same completion transaction. See docs/agents/game-event-ingest.md for how the
 * fixture is authored.
 */
class SessionGameEventsTest extends TestCase
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

    public function test_completion_with_a_valid_game_events_payload_stores_one_row_per_element(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);
        $this->uploadPair($players[0], $session);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => json_encode($this->fixtureEvents()),
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertDatabaseCount('game_events', 7);

        $this->assertDatabaseHas('game_events', [
            'session_id' => $session->id,
            'source' => 'manual',
            'type' => 'kill',
            'side' => 'ally',
            'match_time_ms' => 16000,
            'round_number' => 1,
        ]);

        $this->assertDatabaseHas('game_events', [
            'session_id' => $session->id,
            'source' => 'manual',
            'type' => 'round_lost',
            'side' => null,
            'match_time_ms' => 50000,
            'round_number' => 1,
        ]);
    }

    public function test_completion_without_a_game_events_payload_is_rejected_and_stores_nothing(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Game events are required to complete a session.');

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);
        $this->assertDatabaseCount('game_events', 0);
        $this->assertDatabaseCount('aod_records', 0);
    }

    public function test_completion_accepts_the_game_events_payload_as_an_uploaded_json_file(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);
        $this->uploadPair($players[0], $session);

        $file = UploadedFile::fake()->createWithContent(
            'batch13-prepared-match.json',
            json_encode($this->fixtureEvents()),
        );

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_PROCESSING);

        $this->assertDatabaseCount('game_events', 7);
        $this->assertDatabaseHas('game_events', [
            'session_id' => $session->id,
            'type' => 'round_lost',
            'side' => null,
            'match_time_ms' => 50000,
        ]);
    }

    public function test_an_uploaded_game_events_file_that_is_not_a_json_array_is_rejected(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $file = UploadedFile::fake()->createWithContent('broken.json', 'not json at all');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => $file,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('game_events', 0);
        $this->assertDatabaseCount('aod_records', 0);
    }

    public function test_an_empty_game_events_array_is_rejected_and_stores_nothing(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => '[]',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Game events are required to complete a session.');

        $this->assertDatabaseCount('game_events', 0);
        $this->assertDatabaseCount('aod_records', 0);
    }

    public function test_a_non_json_game_events_payload_is_rejected_and_stores_nothing(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => 'not json at all',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 422)
            ->assertJsonPath('error', true);

        $this->assertDatabaseCount('game_events', 0);
        $this->assertDatabaseCount('aod_records', 0);
    }

    /**
     * @param  array<string, mixed>  $badElement
     */
    #[DataProvider('schemaViolations')]
    public function test_a_schema_invalid_game_event_is_rejected_and_stores_nothing(array $badElement): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => json_encode([$badElement]),
            ])
            ->assertStatus(422);

        $this->assertDatabaseHas('app_sessions', [
            'id' => $session->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);
        $this->assertDatabaseCount('game_events', 0);
        $this->assertDatabaseCount('aod_records', 0);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function schemaViolations(): array
    {
        $valid = ['type' => 'kill', 'side' => 'ally', 'match_time_ms' => 16000, 'round_number' => 1];

        return [
            'type not in the enum' => [['type' => 'ace'] + $valid],
            'type missing' => [array_diff_key($valid, ['type' => ''])],
            'side missing on a kill' => [array_diff_key($valid, ['side' => ''])],
            'side present on a round outcome' => [['type' => 'round_win', 'side' => 'ally', 'match_time_ms' => 1000, 'round_number' => 1]],
            'side not ally or enemy' => [['side' => 'blue'] + $valid],
            'match_time_ms negative' => [['match_time_ms' => -1] + $valid],
            'match_time_ms not an integer' => [['match_time_ms' => '16s'] + $valid],
            'match_time_ms missing' => [array_diff_key($valid, ['match_time_ms' => ''])],
            'round_number below one' => [['round_number' => 0] + $valid],
            'note not a string' => [['note' => 123] + $valid],
            'note longer than 255 chars' => [['note' => str_repeat('x', 256)] + $valid],
        ];
    }

    public function test_the_raw_column_keeps_the_original_payload_element(): void
    {
        [, $coach, $session, $players] = $this->recordingSession(2);
        $this->uploadPair($players[0], $session);

        $element = [
            'type' => 'kill',
            'side' => 'ally',
            'match_time_ms' => 5000,
            'round_number' => 2,
            'note' => null,
            'source_clip' => 'vod@1:23',
        ];

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", [
                'game_events' => json_encode([$element]),
            ])
            ->assertOk();

        $raw = GameEvent::where('session_id', $session->id)->sole()->raw;

        $this->assertArrayHasKey('source_clip', $raw);
        $this->assertSame('vod@1:23', $raw['source_clip']);
    }

    public function test_timeline_surfaces_session_game_events_as_a_top_level_ordered_list(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        GameEvent::factory()->for($session)->create(['type' => 'round_lost', 'side' => null, 'match_time_ms' => 50000, 'round_number' => 1, 'note' => null]);
        GameEvent::factory()->for($session)->create(['type' => 'kill', 'side' => 'ally', 'match_time_ms' => 16000, 'round_number' => 1, 'note' => 'first blood']);
        GameEvent::factory()->for($session)->create(['type' => 'spike_plant', 'side' => 'ally', 'match_time_ms' => 27000, 'round_number' => 1, 'note' => null]);

        $response = $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.game_events.0.type', 'game_event')
            ->assertJsonPath('data.game_events.0.game_type', 'kill')
            ->assertJsonPath('data.game_events.0.side', 'ally')
            ->assertJsonPath('data.game_events.0.match_time_ms', 16000)
            ->assertJsonPath('data.game_events.0.start_ms', 16000)
            ->assertJsonPath('data.game_events.0.end_ms', 16000)
            ->assertJsonPath('data.game_events.0.round_number', 1)
            ->assertJsonPath('data.game_events.0.note', 'first blood')
            ->assertJsonPath('data.game_events.1.game_type', 'spike_plant')
            ->assertJsonPath('data.game_events.2.game_type', 'round_lost')
            ->assertJsonPath('data.game_events.2.side', null);

        $this->assertCount(3, $response->json('data.game_events'));
    }

    public function test_timeline_game_events_is_an_empty_list_when_the_session_has_none(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.game_events', []);
    }

    public function test_timeline_summary_tallies_game_events_by_type_at_team_level(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        GameEvent::factory()->for($session)->create(['type' => 'kill', 'side' => 'ally', 'match_time_ms' => 1000]);
        GameEvent::factory()->for($session)->create(['type' => 'kill', 'side' => 'enemy', 'match_time_ms' => 2000]);
        GameEvent::factory()->for($session)->roundLost()->create(['match_time_ms' => 3000]);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.team.game_events.total', 3)
            ->assertJsonPath('data.team.game_events.by_type.kill', 2)
            ->assertJsonPath('data.team.game_events.by_type.round_lost', 1)
            ->assertJsonPath('data.team.game_events.by_type.spike_defuse', 0);
    }

    public function test_timeline_summary_game_events_are_zero_when_the_session_has_none(): void
    {
        [, $coach, $session] = $this->timelineReadySession();

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.team.game_events.total', 0)
            ->assertJsonPath('data.team.game_events.by_type.kill', 0);
    }

    private function timelineReadySession(): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);
        $session->timeline()->create();
        $this->addParticipant($session, $coach, 'main_coach');

        return [$team, $coach, $session];
    }

    /**
     * The batch 13 fixture, the agreed transcription of one round, reused as
     * the storage and endpoint-output fixture across this file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fixtureEvents(): array
    {
        return json_decode(
            file_get_contents(base_path('tests/Fixtures/GameEvents/batch13-prepared-match.json')),
            true,
        );
    }

    /**
     * An in_progress session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players].
     */
    private function recordingSession(int $players = 2): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');

        $users = [];
        for ($i = 0; $i < $players; $i++) {
            $user = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
            $this->addParticipant($session, $user, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
            $users[] = $user;
        }

        return [$team, $coach, $session, $users];
    }

    /**
     * Delivers $player's own audio and video via the self-service upload
     * endpoint (docs/adr/0012-per-player-recording-uploads.md), the
     * replacement for the old players[] slot on the completion call.
     */
    private function uploadPair(User $player, Session $session): void
    {
        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('aod.mp3', 16, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('vod.mp4', 16, 'video/mp4'),
            ])
            ->assertOk();
    }
}
