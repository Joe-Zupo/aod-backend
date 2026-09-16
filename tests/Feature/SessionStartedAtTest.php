<?php

namespace Tests\Feature;

use App\Models\SessionParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * `started_at` is when the current run began: stamped by every start, cleared
 * when the session returns to the lobby
 * (docs/adr/0015-end-of-run-and-end-of-participation.md).
 */
class SessionStartedAtTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_starting_a_session_stamps_when_the_run_began(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.session.started_at', null);

        Carbon::setTestNow('2026-09-16 18:30:00');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk()
            ->assertJsonPath('data.session.started_at', '2026-09-16T18:30:00.000000Z');

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/sessions/{$session->id}")
            ->assertJsonPath('data.session.started_at', '2026-09-16T18:30:00.000000Z');
    }

    public function test_returning_to_the_lobby_clears_it_and_the_next_start_stamps_it_again(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'queuing');
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player');

        Carbon::setTestNow('2026-09-16 18:30:00');
        $this->actingAs($coach, 'sanctum')->postJson("/api/sessions/{$session->id}/start")->assertOk();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'queuing'])
            ->assertOk()
            ->assertJsonPath('data.session.started_at', null);

        $session->participants()->where('user_id', $player->id)->first()
            ->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_READY);

        Carbon::setTestNow('2026-09-16 18:45:00');
        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk()
            ->assertJsonPath('data.session.started_at', '2026-09-16T18:45:00.000000Z');
    }
}
