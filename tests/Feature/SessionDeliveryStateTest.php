<?php

namespace Tests\Feature;

use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
