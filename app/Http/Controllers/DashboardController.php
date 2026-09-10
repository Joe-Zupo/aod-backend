<?php

namespace App\Http\Controllers;

use App\Http\Resources\TeamResource;
use App\Http\Resources\UserResource;
use App\Models\CommEvent;
use App\Models\Session;
use App\Models\Team;
use App\Models\Transcript;
use App\Models\User;
use App\Support\DashboardMetrics;
use App\Support\TimelineMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The team dashboard read surface (issue #17): a team header carrying a
 * Communication KPI card and a comm-mix card, and a per-player breakdown that
 * a player sees as their own line against the team median.
 */
class DashboardController extends Controller
{
    /**
     * Dashboard Header
     *
     * The team identity block, a Communication KPI over the N most recent
     * analysis-ready sessions, and the same window's communication-mix counts.
     * Any active member: every member sees the same team-wide numbers, only
     * `identity.user` differs.
     */
    public function header(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('viewDashboard', $team);

        $requested = $this->requestedCount($request);
        $pool = $this->pool($team, $requested);
        $short = $pool->count() < $requested;

        return $this->success('Dashboard header retrieved.', [
            'identity' => [
                'team' => new TeamResource($team),
                'user' => new UserResource($request->user()),
                'analysis_ready_count' => $team->sessions()
                    ->where('status', Session::STATUS_ANALYSIS_READY)
                    ->count(),
            ],
            'window' => [
                'sessions_requested' => $requested,
                'sessions_analyzed' => $pool->count(),
                'from' => $pool->min('analysis_ready_at'),
                'to' => $pool->max('analysis_ready_at'),
            ],
            'kpi' => $short
                ? ['message' => 'Insufficient sessions queried for KPI of Communication']
                : $this->kpi($pool),
            'comm_mix' => $short
                ? ['message' => 'Insufficient sessions queried for Communication Mix']
                : $this->commMix($pool),
        ]);
    }

    /**
     * Dashboard Players
     *
     * A per-player communication breakdown over the N most recent
     * analysis-ready sessions. A coach sees every active non-coach member; a
     * player sees only their own line plus the team median.
     */
    public function players(Request $request): JsonResponse
    {
        $team = $request->user()->resolveTeam($request);
        $this->authorize('viewPlayerDashboard', $team);

        $requested = $this->requestedCount($request);
        $pool = $this->pool($team, $requested);

        $window = [
            'sessions_requested' => $requested,
            'sessions_analyzed' => $pool->count(),
            'from' => $pool->min('analysis_ready_at'),
            'to' => $pool->max('analysis_ready_at'),
        ];

        if ($pool->count() < $requested) {
            return $this->success('Dashboard players retrieved.', [
                'window' => $window,
                'message' => 'Insufficient sessions queried for Player Stats',
            ]);
        }

        $pool->load([
            'participants.user',
            'participants.aodRecord.transcript.commEvents.annotations',
        ]);

        $roster = $team->activeMembers()
            ->wherePivotNotIn('member_role', User::TEAM_COACH_ROLES)
            ->get();

        $lines = $roster->map(fn (User $member) => $this->playerLine($pool, $member))->values();

        $caller = $request->user();

        if (! in_array($caller->teamRole($team), User::TEAM_COACH_ROLES, true)) {
            return $this->success('Dashboard players retrieved.', [
                'window' => $window,
                'you' => Arr::only(
                    $this->playerLine($pool, $caller),
                    ['user_id', 'comm_frequency', 'alignment_rate', 'calls_logged'],
                ),
                'team_median' => $this->teamMedian($lines),
            ]);
        }

        return $this->success('Dashboard players retrieved.', [
            'window' => $window,
            'players' => $lines,
        ]);
    }

    /**
     * One player's line: their own communication events pooled over the pool
     * sessions where they have a completed transcript. A player who has no
     * completed transcript anywhere in the pool renders all-zero, alignment
     * null (issue #17).
     *
     * @param  Collection<int, Session>  $pool
     * @return array{user_id: int, username: string, is_online: bool, comm_frequency: float, alignment_rate: float|null, calls_logged: int, sessions_played: int}
     */
    private function playerLine(Collection $pool, User $member): array
    {
        $events = collect();
        $windowMs = 0;
        $played = 0;

        foreach ($pool as $session) {
            $transcript = $session->participants
                ->firstWhere('user_id', $member->id)
                ?->aodRecord?->transcript;

            if ($transcript === null || $transcript->status !== Transcript::STATUS_COMPLETED) {
                continue;
            }

            $played++;
            $windowMs += (int) $transcript->audio_duration_ms;
            $events = $events->merge($transcript->commEvents);
        }

        return [
            'user_id' => $member->id,
            'username' => $member->username,
            'is_online' => (bool) $member->is_online,
            'comm_frequency' => TimelineMetrics::frequencyPerMin($events->count(), $windowMs),
            'alignment_rate' => $this->alignmentRate($events),
            'calls_logged' => $events->count(),
            'sessions_played' => $played,
        ];
    }

    /**
     * The team median of each metric across the roster lines, computed
     * independently per metric. Members who played none of the pool are out of
     * the population; for the alignment rate, members whose own value is null
     * drop out of that metric only. A population below two yields null
     * (issue #17).
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{comm_frequency: float|null, alignment_rate: float|null, calls_logged: float|null}
     */
    private function teamMedian(Collection $lines): array
    {
        $withData = $lines->where('sessions_played', '>', 0);

        return [
            'comm_frequency' => $this->medianOfAtLeastTwo($withData->pluck('comm_frequency')->all()),
            'alignment_rate' => $this->medianOfAtLeastTwo(
                $withData->pluck('alignment_rate')->filter(fn ($value) => $value !== null)->values()->all(),
            ),
            'calls_logged' => $this->medianOfAtLeastTwo($withData->pluck('calls_logged')->all()),
        ];
    }

    /**
     * @param  array<int, int|float>  $values
     */
    private function medianOfAtLeastTwo(array $values): ?float
    {
        return count($values) < 2 ? null : DashboardMetrics::median($values);
    }

    /**
     * How many recent analysis-ready sessions to analyze: `?sessions=`,
     * default 3, clamped by validation to 1..50.
     */
    private function requestedCount(Request $request): int
    {
        $validated = $request->validate([
            'sessions' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return (int) ($validated['sessions'] ?? 3);
    }

    /**
     * The N most recent analysis-ready sessions for the team, newest first,
     * ordered by when each last reached analysis_ready (nulls last), id as the
     * tie-break.
     *
     * @return Collection<int, Session>
     */
    private function pool(Team $team, int $n): Collection
    {
        return $team->sessions()
            ->where('status', Session::STATUS_ANALYSIS_READY)
            ->orderByRaw('analysis_ready_at is null')
            ->orderByDesc('analysis_ready_at')
            ->orderByDesc('id')
            ->limit($n)
            ->get();
    }

    /**
     * The Communication KPI card, pooled across the session pool: one
     * frequency over the summed window, one alignment rate over the pooled
     * game-state-alignment annotations, summed dead-air time, and the total
     * communication-event count.
     *
     * @param  Collection<int, Session>  $pool
     * @return array{comm_frequency: float, alignment_rate: float|null, absence_ms: int, calls_classified: int}
     */
    private function kpi(Collection $pool): array
    {
        $events = collect();
        $windowMs = 0;
        $absenceMs = 0;

        foreach ($pool as $session) {
            $events = $events->merge($session->commEventsQuery()->with('annotations')->get());
            $windowMs += $session->windowMs();
            $absenceMs += $session->deadAirPeriods()
                ->get()
                ->sum(fn ($period) => (int) $period->end_ms - (int) $period->start_ms);
        }

        return [
            'comm_frequency' => TimelineMetrics::frequencyPerMin($events->count(), $windowMs),
            'alignment_rate' => $this->alignmentRate($events),
            'absence_ms' => (int) $absenceMs,
            'calls_classified' => $events->count(),
        ];
    }

    /**
     * The comm-mix card, pooled across the session pool: counts by
     * communication type, the redundant tally over that same total, and the
     * dead-air-period count. `calls_classified` repeats here so the card
     * stands alone (= informative + declarative + compound).
     *
     * @param  Collection<int, Session>  $pool
     * @return array{informative: int, declarative: int, compound: int, redundant: int, absence: int, calls_classified: int}
     */
    private function commMix(Collection $pool): array
    {
        $events = collect();
        $periods = 0;

        foreach ($pool as $session) {
            $events = $events->merge($session->commEventsQuery()->get());
            $periods += $session->deadAirPeriods()->count();
        }

        $counts = TimelineMetrics::commEventCounts($events);

        return [
            'informative' => $counts['informative'],
            'declarative' => $counts['declarative'],
            'compound' => $counts['compound'],
            'redundant' => TimelineMetrics::redundantCount($events),
            'absence' => (int) $periods,
            'calls_classified' => $counts['total'],
        ];
    }

    /**
     * The share of assessed callouts that did not read as a bad moment:
     * (assessed - possibly_negative) / assessed, as a percent. A valence-
     * derived proxy, not a correctness verdict (ADR 0008). Null when nothing
     * in the pool was assessed.
     *
     * @param  Collection<int, CommEvent>  $events
     */
    private function alignmentRate(Collection $events): ?float
    {
        $counts = TimelineMetrics::alignmentCounts($events);

        if ($counts['assessed_total'] === 0) {
            return null;
        }

        return round(
            ($counts['assessed_total'] - $counts['possibly_negative']) / $counts['assessed_total'] * 100,
            2,
        );
    }
}
