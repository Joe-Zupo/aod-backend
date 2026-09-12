<?php

namespace Tests\Feature\Seeders;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_running_it_twice_does_not_duplicate_the_demo_team_or_its_members(): void
    {
        (new TeamSeeder())->run();
        (new TeamSeeder())->run();

        $this->assertSame(1, Team::where('team_name', 'Thunderbolts')->count());
        $this->assertSame(1, User::where('username', 'maincoach')->count());
        $this->assertSame(1, User::where('username', 'player1')->count());
    }
}
