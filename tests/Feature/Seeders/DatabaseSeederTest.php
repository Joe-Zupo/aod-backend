<?php

namespace Tests\Feature\Seeders;

use App\Models\Session;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The full `php artisan db:seed` chain (issue #20): a fresh DB gets the demo
 * team plus an analysis_ready and a timeline_ready demo session, and running
 * it again is a no-op rather than a duplicate.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_seed_yields_the_demo_team_and_both_demo_sessions(): void
    {
        $this->seed();

        $team = Team::where('team_name', 'Thunderbolts')->sole();

        $this->assertSame(
            1,
            Session::where('team_id', $team->id)->where('status', Session::STATUS_ANALYSIS_READY)->count(),
        );
        $this->assertSame(
            1,
            Session::where('team_id', $team->id)->where('status', Session::STATUS_TIMELINE_READY)->count(),
        );
    }

    public function test_seeding_twice_does_not_duplicate_anything(): void
    {
        $this->seed();
        $this->seed();

        $team = Team::where('team_name', 'Thunderbolts')->sole();

        $this->assertSame(2, Session::where('team_id', $team->id)->count());
    }
}
