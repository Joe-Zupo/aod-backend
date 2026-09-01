<?php

namespace Tests\Feature;

use App\Events\SessionParticipantJoined;
use App\Events\SessionParticipantLeft;
use App\Events\SessionParticipantStatusChanged;
use App\Events\SessionStatusChanged;
use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

class SessionBroadcastingTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }

        Event::fake([
            SessionParticipantJoined::class,
            SessionParticipantLeft::class,
            SessionParticipantStatusChanged::class,
            SessionStatusChanged::class,
        ]);

        Storage::fake('local');
    }

    public function test_consent_dispatches_session_participant_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantStatusChanged::class,
            fn ($event) => $event->participant->user_id === $player->id
                && $event->participant->participant_status === SessionParticipant::PARTICIPANT_STATUS_READY,
        );
    }

    public function test_idempotent_consent_dispatches_no_status_change(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/consent")
            ->assertOk();

        Event::assertNotDispatched(SessionParticipantStatusChanged::class);
    }

    public function test_start_dispatches_a_status_change_for_each_recording_player_only(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $one, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);
        $this->addParticipant($session, $two, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk();

        Event::assertDispatchedTimes(SessionParticipantStatusChanged::class, 2);
        Event::assertNotDispatched(
            SessionParticipantStatusChanged::class,
            fn ($event) => $event->participant->user_id === $coach->id,
        );
    }

    public function test_explicit_join_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_rejoining_after_leaving_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player)->leave();

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($player, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/join")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_creating_a_session_auto_joins_the_creator_and_dispatches_session_participant_joined(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/teams/{$team->id}/sessions", ['session_name' => 'Scrim vs Team B'])
            ->assertCreated();

        Event::assertDispatched(
            SessionParticipantJoined::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
    }

    public function test_logout_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->withToken($player->createToken('auth-token')->plainTextToken)
            ->postJson('/api/logout')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_member_removal_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->deleteJson("/api/teams/members/{$player->id}")
            ->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_self_leave_dispatches_session_participant_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($player, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_disbanding_the_team_dispatches_session_participant_left_for_every_member(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')->postJson('/api/teams/leave')->assertOk();

        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $player->id
        );
    }

    public function test_cancelling_a_session_dispatches_session_participant_left_for_each_active_participant(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player);

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cancel")
            ->assertOk();

        Event::assertDispatched(SessionParticipantLeft::class, 2);
    }

    public function test_cancelling_a_session_does_not_dispatch_for_a_participant_who_already_left(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, 'in_progress');
        $session->joinOrRejoin($coach);
        $session->joinOrRejoin($player)->leave();

        Event::fake([SessionParticipantJoined::class, SessionParticipantLeft::class]);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cancel")
            ->assertOk();

        Event::assertDispatched(SessionParticipantLeft::class, 1);
        Event::assertDispatched(
            SessionParticipantLeft::class,
            fn ($event) => $event->participant->user_id === $coach->id
        );
    }

    public function test_starting_a_session_dispatches_session_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_READY);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertOk();

        Event::assertDispatched(
            SessionStatusChanged::class,
            fn ($event) => $event->session->id === $session->id
                && $event->session->status === Session::STATUS_IN_PROGRESS,
        );
    }

    public function test_a_failed_start_dispatches_no_session_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/start")
            ->assertStatus(422);

        Event::assertNotDispatched(SessionStatusChanged::class);
    }

    public function test_cancelling_a_session_dispatches_session_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $session = $this->createSession($team, $coach);
        $this->addParticipant($session, $coach, 'main_coach');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/cancel")
            ->assertOk();

        Event::assertDispatched(
            SessionStatusChanged::class,
            fn ($event) => $event->session->id === $session->id
                && $event->session->status === Session::STATUS_CANCELLED,
        );
    }

    public function test_completing_a_session_dispatches_session_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [[
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4'),
            ]]])
            ->assertOk();

        Event::assertDispatched(
            SessionStatusChanged::class,
            fn ($event) => $event->session->id === $session->id
                && $event->session->status === Session::STATUS_COMPLETED,
        );
    }

    public function test_a_failed_complete_dispatches_no_session_status_changed(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_QUEUING);
        $this->addParticipant($session, $player, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [[
                'user_id' => $player->id,
                'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
                'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4'),
            ]]])
            ->assertStatus(422);

        Event::assertNotDispatched(SessionStatusChanged::class);
    }

    public function test_completing_a_session_dispatches_a_status_change_for_each_swept_participant(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $one = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $two = $this->makeAndAttachMember($team, 'player', 'Player', $coach);
        $session = $this->createSession($team, $coach, Session::STATUS_IN_PROGRESS);
        $this->addParticipant($session, $coach, 'main_coach');
        $this->addParticipant($session, $one, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);
        $this->addParticipant($session, $two, 'player', SessionParticipant::PARTICIPANT_STATUS_RECORDING);

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/sessions/{$session->id}/complete", ['players' => [
                [
                    'user_id' => $one->id,
                    'audio' => UploadedFile::fake()->create('a.mp3', 16, 'audio/mpeg'),
                    'video' => UploadedFile::fake()->create('v.mp4', 16, 'video/mp4'),
                ],
                ['user_id' => $two->id],
            ]])
            ->assertOk();

        Event::assertDispatchedTimes(SessionParticipantStatusChanged::class, 2);
        Event::assertNotDispatched(
            SessionParticipantStatusChanged::class,
            fn ($event) => $event->participant->user_id === $coach->id,
        );
    }
}
