<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamKeyword;
use App\Models\TeamSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamKeywordDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_team_seeds_the_default_informative_and_declarative_keywords(): void
    {
        $team = Team::create(['team_name' => 'Aces of Dawn', 'team_code' => 'TM-AODTEAM1']);

        $informative = $team->settings()->where('setting_name', TeamSettings::SETTING_INFORMATIVE_KEYWORDS)->first();
        $declarative = $team->settings()->where('setting_name', TeamSettings::SETTING_DECLARATIVE_KEYWORDS)->first();

        $this->assertSame(count(TeamKeyword::DEFAULT_INFORMATIVE_KEYWORDS), $informative->keywords()->count());
        $this->assertSame(count(TeamKeyword::DEFAULT_DECLARATIVE_KEYWORDS), $declarative->keywords()->count());

        $this->assertDatabaseHas('team_keywords', [
            'team_settings_id' => $informative->id,
            'keyword' => 'planted',
            'category' => 'informative',
        ]);

        $this->assertDatabaseHas('team_keywords', [
            'team_settings_id' => $declarative->id,
            'keyword' => 'flashing',
            'category' => 'declarative',
        ]);
    }
}
