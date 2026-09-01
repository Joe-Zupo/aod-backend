<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTeamKeywordsRequest;
use App\Http\Requests\UpdateTeamSettingsRequest;
use App\Http\Resources\TeamSettingsResource;
use App\Models\Team;
use App\Models\TeamKeyword;
use App\Models\TeamSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamSettingsController extends Controller
{
    /**
     * Team Settings Return
     *
     * Return the active team's detection settings (dead-air threshold). Restricted
     * to any active Coach, main or assistant.
     */
    public function show(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorizeSettings('view', $team);
        $settings = $team->ensureSettings();

        return $this->success('Team settings retrieved.', [
            'settings' => new TeamSettingsResource($settings),
        ]);
    }

    /**
     * Update Team Settings
     *
     * Update the active team's dead-air threshold. Restricted to any active
     * Coach, main or assistant.
     */
    public function update(UpdateTeamSettingsRequest $request): JsonResponse
    {
        // Authorized by UpdateTeamSettingsRequest::authorize(), which runs
        // before validation — so an unauthorized caller is denied regardless
        // of whether their payload is well-formed.
        $team = $request->user()->resolveTeam($request);
        $settings = $team->ensureSettings();

        $settings->get(TeamSettings::SETTING_DEAD_AIR_THRESHOLD)->update([
            'setting_parameter' => $request->validated('dead_air_threshold_ms'),
        ]);

        return $this->success('Team settings updated.', [
            'settings' => new TeamSettingsResource($settings),
        ]);
    }

    /**
     * Update Team Keywords
     *
     * Replace the active team's detection keyword list. The request carries the
     * complete desired list per category; the server reconciles it against
     * what's stored, inserting new words and deleting dropped ones. Restricted
     * to any active Coach, main or assistant.
     */
    public function updateKeywords(UpdateTeamKeywordsRequest $request): JsonResponse
    {
        // Authorized by UpdateTeamKeywordsRequest::authorize(), which runs
        // before validation — so an unauthorized caller is denied regardless
        // of whether their payload is well-formed.
        $team = $request->user()->resolveTeam($request);
        $settings = $team->ensureSettings();

        $buckets = [
            [$settings->get(TeamSettings::SETTING_INFORMATIVE_KEYWORDS), TeamKeyword::CATEGORY_INFORMATIVE, $request->validated('informative_keywords')],
            [$settings->get(TeamSettings::SETTING_DECLARATIVE_KEYWORDS), TeamKeyword::CATEGORY_DECLARATIVE, $request->validated('declarative_keywords')],
        ];

        DB::transaction(function () use ($buckets): void {
            foreach ($buckets as [$bucket, $category, $keywords]) {
                $this->reconcileKeywords($bucket, $category, $keywords);
            }
        });

        // Rebuild from fresh rows: the collection above still holds the
        // pre-reconcile keyword relations.
        return $this->success('Team keywords updated.', [
            'settings' => new TeamSettingsResource($team->ensureSettings()),
        ]);
    }

    /**
     * Drive one keyword-bucket row to exactly $keywords: insert words not yet
     * present, delete rows no longer wanted. Matching is case-insensitive — the
     * fold happens here rather than in the DB, since SQLite's unique index is
     * case-sensitive where MySQL's collation is not — so a word whose casing is
     * the only change keeps its row, its id, and its stored spelling instead of
     * churning through delete + insert.
     *
     * @param  string[]  $keywords
     */
    private function reconcileKeywords(TeamSettings $bucket, string $category, array $keywords): void
    {
        $desired = collect($keywords)
            ->map(fn (string $word) => trim($word))
            ->keyBy(fn (string $word) => mb_strtolower($word));

        $stored = $bucket->keywords->keyBy(fn (TeamKeyword $row) => mb_strtolower($row->keyword));

        $staleIds = $stored->reject(fn (TeamKeyword $row, string $key) => $desired->has($key))->pluck('id');

        if ($staleIds->isNotEmpty()) {
            TeamKeyword::whereIn('id', $staleIds)->delete();
        }

        $now = now();
        $newRows = $desired
            ->reject(fn (string $word, string $key) => $stored->has($key))
            ->map(fn (string $word) => [
                'team_settings_id' => $bucket->id,
                'keyword' => $word,
                'category' => $category,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        if ($newRows !== []) {
            TeamKeyword::insertOrIgnore($newRows);
        }
    }

    /**
     * Authorize against an unsaved TeamSettings so a denied caller never causes
     * a settings row to be created as a side effect of ensureSettings() below.
     */
    private function authorizeSettings(string $ability, Team $team): void
    {
        $this->authorize($ability, TeamSettings::unsavedFor($team));
    }
}
