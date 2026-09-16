<?php

namespace Tests\Feature;

use App\Events\SessionParticipantLeft;
use App\Events\SessionParticipantRecordingUploaded;
use App\Events\SessionParticipantStatusChanged;
use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * Delivery State: each participant carries the recordings they have stored
 * for the current run, so a Coach can see who has delivered before completing
 * (docs/adr/0015-end-of-run-and-end-of-participation.md).
 */
class SessionDeliveryStateTest extends TestCase
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

    public function test_each_participant_carries_the_recordings_they_have_delivered(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_DELIVERING);
        $this->addParticipant($session, $coach, 'main_coach');
        $part = $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $aod = AodRecord::factory()->for($part)->create(['size_bytes' => 4_194_304, 'mime_type' => 'audio/webm']);

        $participants = collect(
            $this->actingAs($coach, 'sanctum')
                ->getJson("/api/sessions/{$session->id}")
                ->assertOk()
                ->json('data.session.participants'),
        )->keyBy('user_id');

        $this->assertSame([
            'id' => $aod->id,
            'original_filename' => 'aod.mp3',
            'mime_type' => 'audio/webm',
            'size_bytes' => 4_194_304,
            'client_started_at' => null,
        ], $participants[$player->id]['aod']);
        $this->assertArrayHasKey('vod', $participants[$player->id]);
        $this->assertNull($participants[$player->id]['vod']);

        $this->assertArrayHasKey('aod', $participants[$coach->id]);
        $this->assertNull($participants[$coach->id]['aod']);
        $this->assertNull($participants[$coach->id]['vod']);
    }

    public function test_an_upload_broadcasts_the_uploaders_delivery_state(): void
    {
        Event::fake([SessionParticipantRecordingUploaded::class]);
        [, $player, $session] = $this->deliveringSession();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('take.webm', 64, 'audio/webm'),
            ])
            ->assertOk();

        Event::assertDispatchedTimes(SessionParticipantRecordingUploaded::class, 1);
        Event::assertDispatched(SessionParticipantRecordingUploaded::class, function ($event) use ($player, $session) {
            $payload = json_decode(json_encode($event->broadcastWith()), true);

            return $event->broadcastOn()[0]->name === "private-session.{$session->id}"
                && $payload['user_id'] === $player->id
                && $payload['participant_status'] === 'recording'
                && $payload['aod']['original_filename'] === 'take.webm'
                && $payload['aod']['size_bytes'] === 64 * 1024
                && $payload['vod'] === null;
        });
    }

    public function test_a_refused_upload_broadcasts_nothing(): void
    {
        Event::fake([SessionParticipantRecordingUploaded::class]);
        [, $player, $session, $part] = $this->deliveringSession();
        $part->update(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT]);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/recording", [
                'audio' => UploadedFile::fake()->create('take.webm', 64, 'audio/webm'),
            ])
            ->assertStatus(422);

        Event::assertNotDispatched(SessionParticipantRecordingUploaded::class);
    }

    public function test_a_departure_broadcasts_the_cleared_delivery_state(): void
    {
        Event::fake([SessionParticipantLeft::class]);
        [, $player, $session, $part] = $this->deliveringSession();
        AodRecord::factory()->for($part)->create();
        VodRecord::factory()->for($part)->create();
        $part->load(['aodRecord', 'vodRecord']);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/leave")
            ->assertOk();

        Event::assertDispatched(SessionParticipantLeft::class, function ($event) use ($player) {
            $payload = $event->broadcastWith();

            return $payload['user_id'] === $player->id
                && array_key_exists('aod', $payload) && $payload['aod'] === null
                && array_key_exists('vod', $payload) && $payload['vod'] === null;
        });
    }

    public function test_a_status_change_carries_the_delivery_state_it_leaves_in_place(): void
    {
        Event::fake([SessionParticipantStatusChanged::class]);
        Queue::fake();
        [$coach, $player, $session, $part] = $this->deliveringSession();
        $aod = AodRecord::factory()->for($part)->create();
        VodRecord::factory()->for($part)->create();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['game_events' => $this->stubGameEvents()])
            ->assertOk();

        Event::assertDispatched(SessionParticipantStatusChanged::class, function ($event) use ($player, $aod) {
            $payload = json_decode(json_encode($event->broadcastWith()), true);

            return $payload['user_id'] === $player->id
                && $payload['aod']['id'] === $aod->id
                && $payload['vod'] !== null;
        });
    }

    /**
     * @return array{0: User, 1: User, 2: Session, 3: SessionParticipant}
     */
    private function deliveringSession(string $status = Session::STATUS_DELIVERING): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, $status);
        $this->addParticipant($session, $coach, 'main_coach');
        $part = $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        return [$coach, $player, $session, $part];
    }
}
