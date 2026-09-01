<?php

namespace Tests\Concerns;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;

/**
 * Shared team/session fixture builders for feature tests. The single source
 * of truth for this setup shape, so two test files can't quietly drift into
 * different defaults for the same fixture (as happened before this trait
 * existed: one copy's createSession() silently dropped the $name param the
 * other had).
 */
trait CreatesTeamsAndSessions
{
    private function makeTeamWithMember(string $memberRole, string $spatieRole = 'Coach'): array
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        $team = Team::factory()->create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $this->attachActiveMember($team, $user, $memberRole, $user);

        return [$team, $user];
    }

    private function attachActiveMember(Team $team, User $user, string $memberRole, User $decidedBy): void
    {
        $team->members()->attach($user, [
            'member_role' => $memberRole,
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
        ]);
    }

    private function makeAndAttachMember(Team $team, string $memberRole, string $spatieRole, User $decidedBy): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);
        $this->attachActiveMember($team, $user, $memberRole, $decidedBy);

        return $user;
    }

    private function createSession(Team $team, User $creator, string $status = 'queuing', string $name = 'Scrim vs Team B'): Session
    {
        return Session::factory()->for($team)->create([
            'created_by' => $creator->id,
            'session_name' => $name,
            'status' => $status,
        ]);
    }

    private function addParticipant(
        Session $session,
        User $user,
        string $role,
        string $status = SessionParticipant::PARTICIPANT_STATUS_READY,
    ): SessionParticipant {
        return SessionParticipant::factory()->for($session)->create([
            'user_id' => $user->id,
            'participant_role' => $role,
            'participant_status' => $status,
        ]);
    }
}
