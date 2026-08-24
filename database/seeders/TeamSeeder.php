<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    /**
     * Seed a demo team with a main coach, an assistant coach, and 5 players.
     * All passwords match the username (e.g. maincoach / maincoach).
     */
    public function run(): void
    {
        $team = new Team(['team_name' => 'Thunderbolts']);
        $team->team_code = Team::generateTeamCode();
        $team->save();

        $mainCoach = $this->createUser('maincoach', 'Coach');
        $this->attach($team, $mainCoach, 'main_coach', $mainCoach);

        $assistantCoach = $this->createUser('assistantcoach', 'Coach');
        $this->attach($team, $assistantCoach, 'assistant_coach', $mainCoach);

        for ($i = 1; $i <= 5; $i++) {
            $player = $this->createUser("player{$i}", 'Player');
            $this->attach($team, $player, 'player', $mainCoach);
        }
    }

    private function createUser(string $username, string $role): User
    {
        $user = User::create([
            'username' => $username,
            'email' => "{$username}@example.com",
            'password' => $username,
            'user_code' => User::generateUserCode($role),
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function attach(Team $team, User $user, string $memberRole, User $decidedBy): void
    {
        $team->members()->attach($user->id, [
            'member_role' => $memberRole,
            'status' => 'active',
            'joined_at' => now(),
            'decided_by' => $decidedBy->id,
            'decided_at' => now(),
        ]);
    }
}
