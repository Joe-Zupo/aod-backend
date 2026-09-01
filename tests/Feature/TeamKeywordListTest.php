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

    public function test_a_missing_category_key_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', ['informative_keywords' => ['alpha']])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.declarative_keywords.0', fn ($m) => is_string($m));
    }

    public function test_a_non_string_entry_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha', 123],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_an_entry_with_interior_whitespace_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['flash bang'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_surrounding_whitespace_is_trimmed_and_the_entry_accepted(): void
    {
        [$team, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['  bravo  '],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $this->assertSame(['bravo'], $this->informativeKeywords($team));
    }

    public function test_an_entry_longer_than_64_characters_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => [str_repeat('a', 65)],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_an_empty_string_entry_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['   '],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_more_than_200_entries_in_a_category_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => array_map(fn ($i) => "word{$i}", range(1, 201)),
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_case_insensitive_duplicates_within_a_payload_are_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['A', 'a'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.informative_keywords.0', fn ($m) => is_string($m));
    }

    public function test_exact_duplicates_within_a_payload_are_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['flash', 'flash'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertStatus(422);
    }

    public function test_a_word_in_both_categories_is_rejected(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['smoke'],
                'declarative_keywords' => ['Smoke'],
            ])
            ->assertStatus(422)
            ->assertJsonPath('data.errors.declarative_keywords.0', fn ($m) => is_string($m));
    }

    public function test_an_assistant_coach_can_update_the_keyword_list(): void
    {
        [$team, $mainCoach] = $this->teamWithKeywords(['alpha'], ['pushing']);
        $assistant = $this->makeAndAttachMember($team, 'assistant_coach', 'Coach', $mainCoach);

        $this->actingAs($assistant, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['bravo'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $this->assertSame(['bravo'], $this->informativeKeywords($team));
    }

    public function test_a_player_cannot_update_the_keyword_list(): void
    {
        [$team, $mainCoach] = $this->teamWithKeywords(['alpha'], ['pushing']);
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $mainCoach);

        $this->actingAs($player, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['bravo'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertForbidden();

        $this->assertSame(['alpha'], $this->informativeKeywords($team));
    }

    public function test_a_player_sending_an_invalid_payload_is_forbidden_not_a_validation_error(): void
    {
        [$team, $mainCoach] = $this->teamWithKeywords(['alpha'], ['pushing']);
        $player = $this->makeAndAttachMember($team, 'player', 'Player', $mainCoach);

        $this->actingAs($player, 'sanctum')
            ->putJson('/api/teams/settings/keywords', ['informative_keywords' => 'not-an-array'])
            ->assertForbidden();
    }

    public function test_an_outsider_cannot_update_a_teams_keyword_list(): void
    {
        [$team] = $this->teamWithKeywords(['alpha'], ['pushing']);
        $outsider = User::factory()->create();
        $outsider->assignRole('Coach');

        $this->actingAs($outsider, 'sanctum')
            ->putJson('/api/teams/settings/keywords?team='.$team->id, [
                'informative_keywords' => ['bravo'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertNotFound();

        $this->assertSame(['alpha'], $this->informativeKeywords($team));
    }

    public function test_a_coach_of_another_team_cannot_update_this_teams_keyword_list(): void
    {
        [$team] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $otherCoach = User::factory()->create();
        $otherCoach->assignRole('Coach');
        $otherTeam = Team::factory()->create(['team_name' => 'Second Team', 'team_code' => 'TM-SECOND01']);
        $this->attachActiveMember($otherTeam, $otherCoach, 'main_coach', $otherCoach);

        $this->actingAs($otherCoach, 'sanctum')
            ->putJson('/api/teams/settings/keywords?team='.$team->id, [
                'informative_keywords' => ['bravo'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertNotFound();

        $this->assertSame(['alpha'], $this->informativeKeywords($team));
    }

    public function test_the_response_body_matches_the_get_settings_shape(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);

        $get = $this->actingAs($coach, 'sanctum')->getJson('/api/teams/settings')->json('data.settings');

        $put = $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha'],
                'declarative_keywords' => ['pushing'],
            ])->json('data.settings');

        $this->assertSame(array_keys($get), array_keys($put));
    }

    public function test_updated_at_advances_after_a_real_change(): void
    {
        [, $coach] = $this->teamWithKeywords(['alpha'], ['pushing']);
        $before = $this->actingAs($coach, 'sanctum')->getJson('/api/teams/settings')->json('data.settings.updated_at');

        $this->travel(1)->hour();

        $after = $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha', 'bravo'],
                'declarative_keywords' => ['pushing'],
            ])->json('data.settings.updated_at');

        $this->assertNotSame($before, $after);
        $this->assertTrue(strtotime($after) > strtotime($before));
    }

    public function test_it_self_heals_missing_bucket_rows_and_the_submitted_list_wins(): void
    {
        [$team, $coach] = $this->makeTeamWithMember('main_coach');
        $team->settings()->delete();

        $this->actingAs($coach, 'sanctum')
            ->putJson('/api/teams/settings/keywords', [
                'informative_keywords' => ['alpha'],
                'declarative_keywords' => ['pushing'],
            ])
            ->assertOk();

        $this->assertSame(['alpha'], $this->informativeKeywords($team));
        $this->assertSame(['pushing'], $this->declarativeKeywords($team));
        $this->assertDatabaseHas('team_settings', ['team_id' => $team->id, 'setting_name' => 'dead_air_threshold']);
    }
}
