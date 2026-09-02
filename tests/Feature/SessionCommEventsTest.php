<?php

namespace Tests\Feature;

use App\Jobs\AdvanceSessionAfterProcessing;
use App\Jobs\DetectCommEvents;
use App\Jobs\FetchTranscriptSentences;
use App\Jobs\PollTranscription;
use App\Models\AodRecord;
use App\Models\CalloutDetection;
use App\Models\CommEvent;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\TranscriptWord;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Communication-event detection, the processing -> timeline_ready transition it
 * gates, and the timeline read endpoints. See
 * docs/adr/0006-communication-events.md.
 */
class SessionCommEventsTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Storage::fake('local');
    }

    /**
     * An in_progress session with $players recording players plus the creating
     * Coach. Returns [$team, $coach, $session, User[] $players].
     */
    private function recordingSession(int $players = 1): array
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
     * @return array<string, mixed>
     */
    private function pair(User $player): array
    {
        return [
            'user_id' => $player->id,
            'audio' => UploadedFile::fake()->create('aod.mp3', 16, 'audio/mpeg'),
            'video' => UploadedFile::fake()->create('vod.mp4', 16, 'video/mp4'),
        ];
    }

    /**
     * Build a timeline_ready session directly: one recording player with an AOD,
     * a VOD, a completed transcript and whatever comm_events the callback adds.
     *
     * @param  callable(Transcript, SessionParticipant): void|null  $withEvents
     * @return array{0: Team, 1: User, 2: Session, 3: SessionParticipant, 4: Transcript}
     */
    private function timelineReadySession(?callable $withEvents = null, int $audioDurationMs = 60000): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_TIMELINE_READY);
        $session->timeline()->create();
        $this->addParticipant($session, $coach, 'main_coach');

        $player = $this->addParticipant(
            $session,
            $this->makeAndAttachMember($team, 'player', 'Player', $coach),
            'player',
            SessionParticipant::PARTICIPANT_STATUS_COMPLETED,
        );

        $aod = AodRecord::factory()->for($player)->create();
        VodRecord::factory()->for($player)->create();

        $transcript = Transcript::factory()->for($aod)->completed()->create([
            'audio_duration_ms' => $audioDurationMs,
            'comm_events_detected' => true,
        ]);

        if ($withEvents) {
            $withEvents($transcript, $player);
        }

        return [$team, $coach, $session, $player, $transcript];
    }

    private function commEvent(Transcript $transcript, array $attributes = []): CommEvent
    {
        return CommEvent::create(array_merge([
            'transcript_id' => $transcript->id,
            'communication_type' => CommEvent::TYPE_INFORMATIVE,
            'is_redundant' => false,
            'start_ms' => 1000,
            'end_ms' => 1500,
            'content' => 'here',
            'padding_ms' => 2000,
        ], $attributes));
    }

    private function callout(CommEvent $event, Transcript $transcript, array $attributes = []): CalloutDetection
    {
        $word = TranscriptWord::create([
            'transcript_id' => $transcript->id,
            'position' => 0,
            'sentence_position' => 0,
            'word' => $attributes['keyword'] ?? 'here',
            'start_ms' => $attributes['start_ms'] ?? 1000,
            'end_ms' => $attributes['end_ms'] ?? 1200,
            'confidence' => 0.9,
        ]);

        return CalloutDetection::create(array_merge([
            'comm_event_id' => $event->id,
            'transcript_word_id' => $word->id,
            'keyword' => 'here',
            'normalized_keyword' => 'here',
            'category' => 'informative',
            'start_ms' => 1000,
            'end_ms' => 1200,
            'confidence' => 0.9,
        ], $attributes));
    }

    // --- Detection + transition through the real job chain -------------------

    public function test_the_chain_detects_comm_events_and_advances_the_session_to_timeline_ready(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/1']),
            '*/v2/transcript' => Http::response(['id' => 'txn_1', 'status' => 'queued']),
            '*/v2/transcript/txn_1' => Http::response([
                'id' => 'txn_1',
                'status' => 'completed',
                'text' => 'planting here now.',
                'language_code' => 'en',
                'confidence' => 0.9,
                'audio_duration' => 60,
                'words' => [
                    ['text' => 'planting', 'start' => 1000, 'end' => 1400, 'confidence' => 0.9],
                    ['text' => 'here', 'start' => 1500, 'end' => 1700, 'confidence' => 0.9],
                    ['text' => 'now.', 'start' => 1750, 'end' => 2000, 'confidence' => 0.9],
                ],
            ]),
            '*/v2/transcript/txn_1/sentences' => Http::response([
                'id' => 'txn_1',
                'sentences' => [
                    [
                        'text' => 'planting here now.',
                        'start' => 1000,
                        'end' => 2000,
                        'confidence' => 0.9,
                        'words' => [
                            ['text' => 'planting', 'start' => 1000, 'end' => 1400, 'confidence' => 0.9],
                            ['text' => 'here', 'start' => 1500, 'end' => 1700, 'confidence' => 0.9],
                            ['text' => 'now.', 'start' => 1750, 'end' => 2000, 'confidence' => 0.9],
                        ],
                    ],
                ],
            ]),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(1);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [$this->pair($players[0])]])
            ->assertOk();

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);

        $transcript = Transcript::sole();
        $this->assertTrue((bool) $transcript->comm_events_detected);

        $event = CommEvent::sole();
        $this->assertSame($transcript->id, $event->transcript_id);
        // 'planting' is declarative, 'here' informative -> compound.
        $this->assertSame(CommEvent::TYPE_COMPOUND, $event->communication_type);
        $this->assertSame(1000, $event->start_ms);
        $this->assertSame(1700, $event->end_ms);
        $this->assertSame(2000, $event->padding_ms);
        $this->assertFalse($event->is_redundant);

        $this->assertSame(2, $event->calloutDetections()->count());
        $this->assertDatabaseHas('callout_detections', [
            'comm_event_id' => $event->id,
            'normalized_keyword' => 'planting',
            'category' => 'declarative',
        ]);
    }

    public function test_the_session_advances_even_when_one_transcript_fails(): void
    {
        Http::fake([
            '*/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/u/x']),
            '*/v2/transcript' => Http::sequence()
                ->push(['id' => 'txn_a', 'status' => 'queued'])
                ->push(['id' => 'txn_b', 'status' => 'queued']),
            '*/v2/transcript/txn_a' => Http::response([
                'id' => 'txn_a',
                'status' => 'completed',
                'text' => 'rotating.',
                'language_code' => 'en',
                'audio_duration' => 30,
                'words' => [['text' => 'rotating.', 'start' => 500, 'end' => 900, 'confidence' => 0.9]],
            ]),
            '*/v2/transcript/txn_a/sentences' => Http::response([
                'id' => 'txn_a',
                'sentences' => [[
                    'text' => 'rotating.',
                    'start' => 500,
                    'end' => 900,
                    'confidence' => 0.9,
                    'words' => [['text' => 'rotating.', 'start' => 500, 'end' => 900, 'confidence' => 0.9]],
                ]],
            ]),
            '*/v2/transcript/txn_b' => Http::response(['id' => 'txn_b', 'status' => 'error', 'error' => 'bad audio']),
        ]);

        [, $coach, $session, $players] = $this->recordingSession(2);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                $this->pair($players[0]),
                $this->pair($players[1]),
            ]])
            ->assertOk();

        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
        $this->assertSame(1, Transcript::where('status', Transcript::STATUS_COMPLETED)->count());
        $this->assertSame(1, Transcript::where('status', Transcript::STATUS_FAILED)->count());
        $this->assertSame(1, CommEvent::count());
    }

    public function test_detect_comm_events_failed_handler_degrades_an_undetected_transcript_and_advances(): void
    {
        [, , $session, , $transcript] = $this->timelineReadySession();
        $session->update(['status' => Session::STATUS_PROCESSING]);
        $transcript->update(['comm_events_detected' => false]);

        (new DetectCommEvents($transcript))->failed(new \RuntimeException('pcre blew up'));

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertStringContainsString('detection failed', strtolower($transcript->error));
        // The only transcript is now terminal, so the fan-in advances the session.
        $this->assertSame(Session::STATUS_TIMELINE_READY, $session->fresh()->status);
    }

    public function test_detect_comm_events_failed_handler_leaves_an_already_detected_transcript_alone(): void
    {
        [, , , , $transcript] = $this->timelineReadySession();

        (new DetectCommEvents($transcript))->failed(new \RuntimeException('too late'));

        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->fresh()->status);
    }

    public function test_sentence_fetch_failed_handler_does_not_clobber_a_transcript_whose_sentences_exist(): void
    {
        [, , , , $transcript] = $this->timelineReadySession();
        $transcript->sentences()->create([
            'position' => 0, 'text' => 'here.', 'start_ms' => 0, 'end_ms' => 500, 'confidence' => 0.9,
        ]);

        (new FetchTranscriptSentences($transcript))->failed(new \RuntimeException('boom'));

        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->fresh()->status);
    }

    public function test_poll_failed_handler_does_not_clobber_an_already_completed_transcript(): void
    {
        [, , , , $transcript] = $this->timelineReadySession();

        (new PollTranscription($transcript))->failed(new \RuntimeException('inline handoff threw'));

        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->fresh()->status);
    }

    public function test_a_processing_session_does_not_advance_until_every_transcript_is_terminal(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_PROCESSING);
        $this->addParticipant($session, $coach, 'main_coach');

        $done = $this->addParticipant($session, $this->makeAndAttachMember($team, 'player', 'Player', $coach), 'player', SessionParticipant::PARTICIPANT_STATUS_COMPLETED);
        $running = $this->addParticipant($session, $this->makeAndAttachMember($team, 'player', 'Player', $coach), 'player', SessionParticipant::PARTICIPANT_STATUS_COMPLETED);

        Transcript::factory()->for(AodRecord::factory()->for($done))->completed()->create(['comm_events_detected' => true]);
        Transcript::factory()->for(AodRecord::factory()->for($running))->processing('txn_run')->create();

        (new AdvanceSessionAfterProcessing($session))->handle();

        $this->assertSame(Session::STATUS_PROCESSING, $session->fresh()->status);
    }

    // --- Gate: 409 while processing, served at timeline_ready ----------------

    public function test_timeline_endpoints_are_blocked_with_409_while_processing(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach, Session::STATUS_PROCESSING);
        $this->addParticipant($session, $coach, 'main_coach');

        foreach (['captions', 'timeline', 'timeline-summary'] as $endpoint) {
            $this->actingAs($coach, 'sanctum')
                ->getJson("/api/sessions/{$session->id}/{$endpoint}")
                ->assertStatus(409);
        }

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertStatus(409);
    }

    public function test_show_and_timeline_endpoints_serve_a_timeline_ready_session(): void
    {
        [, $coach, $session] = $this->timelineReadySession(function (Transcript $transcript) {
            $event = $this->commEvent($transcript);
            $this->callout($event, $transcript);
        });

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.session.status', Session::STATUS_TIMELINE_READY);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/captions")
            ->assertOk()
            ->assertJsonPath('data.session_id', $session->id);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.status', Session::STATUS_TIMELINE_READY);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.session_id', $session->id);
    }

    // --- timeline endpoint shape -------------------------------------------

    public function test_timeline_returns_timestamps_ordered_by_start_with_callouts_and_participant_metadata(): void
    {
        [, $coach, $session, $player, $transcript] = $this->timelineReadySession(function (Transcript $transcript) {
            $late = $this->commEvent($transcript, ['start_ms' => 40000, 'end_ms' => 41000, 'communication_type' => CommEvent::TYPE_DECLARATIVE, 'content' => 'rotating']);
            $this->callout($late, $transcript, ['keyword' => 'rotating', 'normalized_keyword' => 'rotating', 'category' => 'declarative', 'start_ms' => 40000, 'end_ms' => 40400]);

            $early = $this->commEvent($transcript, ['start_ms' => 5000, 'end_ms' => 5600, 'content' => 'here']);
            $this->callout($early, $transcript, ['start_ms' => 5000, 'end_ms' => 5200]);
        });

        $response = $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.timeline.duration_ms', null)
            ->assertJsonPath('data.timestamps.0.type', 'communication_event')
            ->assertJsonPath('data.timestamps.0.start_ms', 5000)
            ->assertJsonPath('data.timestamps.1.start_ms', 40000)
            ->assertJsonPath('data.timestamps.0.participant_id', $player->id)
            ->assertJsonPath('data.timestamps.1.callouts.0.normalized_keyword', 'rotating');

        $this->assertSame($transcript->id, $response->json('data.participants.0.transcript.id'));
        $this->assertNotNull($response->json('data.participants.0.aod.original_filename'));
        $this->assertNotNull($response->json('data.participants.0.vod.id'));
        $this->assertArrayNotHasKey('path', $response->json('data.participants.0.aod'));
    }

    // --- timeline-summary math -------------------------------------------

    public function test_timeline_summary_reports_frequency_counts_and_a_silence_proxy(): void
    {
        [, $coach, $session] = $this->timelineReadySession(function (Transcript $transcript) {
            // window 60000ms. Two informative (redundant), one declarative, one compound.
            $a = $this->commEvent($transcript, ['start_ms' => 0, 'end_ms' => 10000, 'is_redundant' => true]);
            $this->callout($a, $transcript);
            $b = $this->commEvent($transcript, ['start_ms' => 20000, 'end_ms' => 25000, 'communication_type' => CommEvent::TYPE_DECLARATIVE]);
            $this->callout($b, $transcript, ['category' => 'declarative']);
            $c = $this->commEvent($transcript, ['start_ms' => 24000, 'end_ms' => 30000, 'communication_type' => CommEvent::TYPE_COMPOUND]);
            $this->callout($c, $transcript);
            $d = $this->commEvent($transcript, ['start_ms' => 55000, 'end_ms' => 56000]);
            $this->callout($d, $transcript);
        }, 60000);

        $response = $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}/timeline-summary")
            ->assertOk()
            ->assertJsonPath('data.session_window_ms', 60000)
            ->assertJsonPath('data.team.counts.informative', 2)
            ->assertJsonPath('data.team.counts.declarative', 1)
            ->assertJsonPath('data.team.counts.compound', 1)
            ->assertJsonPath('data.team.counts.redundant', 1)
            ->assertJsonPath('data.team.counts.total', 4);

        $this->assertEqualsWithDelta(4.0, $response->json('data.team.frequency_per_min'), 0.001);

        // Talk spans merge to [0,10000] + [20000,30000] + [55000,56000] = 21000ms.
        $this->assertSame(21000, $response->json('data.team.total_talk_ms'));
        $this->assertSame(39000, $response->json('data.team.total_silence_ms'));
        // Longest gap is 20000 -> 30000..55000 = 25000ms.
        $this->assertSame(25000, $response->json('data.team.longest_silence_ms'));

        $this->assertEqualsWithDelta(4.0, $response->json('data.participants.0.frequency_per_min'), 0.001);
    }

    public function test_timeline_endpoints_require_an_active_team_member(): void
    {
        [, , $session] = $this->timelineReadySession();
        $outsider = User::factory()->create();

        foreach (['timeline', 'timeline-summary'] as $endpoint) {
            $this->actingAs($outsider, 'sanctum')
                ->getJson("/api/sessions/{$session->id}/{$endpoint}")
                ->assertNotFound();
        }
    }
}
