<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTeamsAndSessions;
use Tests\TestCase;

class TeamKeywordListTest extends TestCase
{
    use CreatesTeamsAndSessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Coach', 'Player'] as $role) {
            Role::create(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function informativeKeywords($team): array
    {
        return $team->settings()
            ->where('setting_name', 'informative_keywords')
            ->first()
            ->keywords()
            ->pluck('keyword')
            ->all();
    }

    private function declarativeKeywords($team): array
    {
        return $team->settings()
            ->where('setting_name', 'declarative_keywords')
            ->first()
            ->keywords()
            ->pluck('keyword')
            ->all();
    }

    /** @return array{0: Team, 1: User} */
    private function teamWithKeywords(array $informative, array $declarative): array
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $informativeBucket = $team->settings()->where('setting_name', 'informative_keywords')->first();
        $declarativeBucket = $team->settings()->where('setting_name', 'declarative_keywords')->first();
        $informativeBucket->keywords()->delete();
        $declarativeBucket->keywords()->delete();
        $informativeBucket->seedKeywords('informative', $informative);
        $declarativeBucket->seedKeywords('declarative', $declarative);

        return [$team, $coach];
    }

    public function test_main_coach_replaces_each_category_with_the_submitted_list(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha', 'bravo', 'charlie'],
                'declarative_keywords' => ['pushing', 'holding'],
            ])
            ->assertOk()
            ->assertJsonCount(3, 'data.settings.informative_keywords')
            ->assertJsonCount(2, 'data.settings.declarative_keywords');

        $this->assertEqualsCanonicalizing(['alpha', 'bravo', 'charlie'], $this->informativeKeywords($team));
        $this->assertEqualsCanonicalizing(['pushing', 'holding'], $this->declarativeKeywords($team));
    }

    public function test_new_words_are_inserted_and_existing_rows_keep_their_ids(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['alpha', 'bravo'], ['pushing']);
        $keptId = $team->settings()->where('setting_name', 'informative_keywords')->first()
            ->keywords()->where('keyword', 'alpha')->value('id');

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha', 'bravo', 'charlie'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['alpha', 'bravo', 'charlie'], $this->informativeKeywords($team));
        $this->assertDatabaseHas('team_keywords', ['id' => $keptId, 'keyword' => 'alpha']);
    }

    public function test_words_absent_from_the_payload_are_deleted(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['alpha', 'bravo', 'charlie'], ['pushing', 'holding']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha'],
                'declarative_keywords' => ['pushing', 'holding'],
            ])
            ->assertOk();

        $this->assertEqualsCanonicalizing(['alpha'], $this->informativeKeywords($team));
        $this->assertDatabaseMissing('team_keywords', ['keyword' => 'bravo']);
        $this->assertDatabaseMissing('team_keywords', ['keyword' => 'charlie']);
    }

    public function test_an_empty_array_clears_a_category(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['alpha', 'bravo'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => [],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk()
            ->assertJsonCount(0, 'data.settings.informative_keywords');

        $this->assertSame([], $this->informativeKeywords($team));
        $this->assertEqualsCanonicalizing(['pushing'], $this->declarativeKeywords($team));
    }

    public function test_resubmitting_the_identical_list_changes_no_rows(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['alpha', 'bravo'], ['pushing']);
        $before = $team->settings()->where('setting_name', 'informative_keywords')->first()
            ->keywords()->orderBy('id')->get(['id', 'keyword', 'updated_at']);

        $this->travel(1)->hour();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['bravo', 'alpha'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $after = $team->settings()->where('setting_name', 'informative_keywords')->first()
            ->keywords()->orderBy('id')->get(['id', 'keyword', 'updated_at']);

        $this->assertEquals(
            $before->map->only(['id', 'keyword'])->all(),
            $after->map->only(['id', 'keyword'])->all(),
        );
        $this->assertEquals(
            $before->pluck('updated_at')->map->toDateTimeString()->all(),
            $after->pluck('updated_at')->map->toDateTimeString()->all(),
        );
    }

    public function test_a_casing_only_change_keeps_the_stored_row_and_spelling(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['flashing'], ['pushing']);
        $rowId = $team->settings()->where('setting_name', 'informative_keywords')->first()
            ->keywords()->where('keyword', 'flashing')->value('id');

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['FLASHING'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('team_keywords', ['id' => $rowId, 'keyword' => 'flashing']);
        $this->assertDatabaseMissing('team_keywords', ['keyword' => 'FLASHING']);
        $this->assertSame(['flashing'], $this->informativeKeywords($team));
    }
}
