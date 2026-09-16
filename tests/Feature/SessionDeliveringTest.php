<?php

namespace Tests\Feature;

use App\Events\SessionStatusChanged;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

/**
 * `delivering` is the end of a run: the Coach declares the match over, every
 * client finishes its recorder and uploads, and only then can the session be
 * completed (docs/adr/0015-end-of-run-and-end-of-participation.md).
 */
class SessionDeliveringTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\User, 2: Session}
     */
    private function recordingSession(string $status = Session::STATUS_IN_PROGRESS): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, $status);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        return [$coach, $player, $session];
    }

    public function test_a_coach_ends_the_run_by_moving_an_in_progress_session_to_delivering(): void
    {
        Event::fake([SessionStatusChanged::class]);
        [$coach, , $session] = $this->recordingSession();

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'delivering'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'delivering');

        $this->assertSame(Session::STATUS_DELIVERING, $session->fresh()->status);
        Event::assertDispatched(
            SessionStatusChanged::class,
            fn (SessionStatusChanged $event) => $event->session->is($session) && $event->session->status === 'delivering',
        );
    }

    public static function statusesThatCannotFinish(): array
    {
        return [
            'queuing' => [Session::STATUS_QUEUING],
            'delivering' => [Session::STATUS_DELIVERING],
            'processing' => [Session::STATUS_PROCESSING],
        ];
    }

    #[DataProvider('statusesThatCannotFinish')]
    public function test_only_an_in_progress_session_can_move_to_delivering(string $status): void
    {
        [$coach, , $session] = $this->recordingSession($status);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'delivering'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only a recording session can be finished.');

        $this->assertSame($status, $session->fresh()->status);
    }

    public function test_a_delivering_session_is_the_teams_live_session(): void
    {
        [$coach, , $session] = $this->recordingSession(Session::STATUS_DELIVERING);

        $this->actingAs($coach, 'sanctum')
            ->getJson("/api/teams/{$session->team_id}/sessions")
            ->assertOk()
            ->assertJsonPath('data.live_session.id', $session->id)
            ->assertJsonPath('data.live_session.status', 'delivering');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$session->team_id}/sessions", ['session_name' => 'Second scrim'])
            ->assertStatus(422);
    }

    public function test_a_player_cannot_move_a_session_to_delivering(): void
    {
        [, $player, $session] = $this->recordingSession();

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/transitions", ['to' => 'delivering'])
            ->assertForbidden();

        $this->assertSame(Session::STATUS_IN_PROGRESS, $session->fresh()->status);
    }
}
