# 11. Team dashboard

> Adds a read-only aggregate surface over analysis-ready sessions. Adds one
> lifecycle column, `app_sessions.analysis_ready_at`, stamped by
> `Session::markAnalysisReady()` (ADR 0010).
> Uses, but does not change, the timeline metrics from ADR 0006 and the
> game-state-alignment assessment from ADR 0008.

Every endpoint so far answers a question about one session. A coach preparing
for a review block, or a player checking their own trend, wants the opposite:
one number per axis rolled up over the last few sessions. This ADR adds that
surface. It computes nothing new about a session; it pools what ADR 0006, 0008
and 0009 already produce.

## Decision

### Two endpoints, caller's active team

- `GET /api/dashboard/header` -- any active member.
- `GET /api/dashboard/players` -- any active member.

Both resolve the team through `User::resolveTeam()` (the caller's active team,
`?team={id}` to override), the same helper `/api/teams/settings` uses. Both
authorize with an active-member check on `TeamPolicy` (`viewDashboard` /
`viewPlayerDashboard`, kept as two abilities so they can diverge later). There
is no `Dashboard` model, so the checks live on `TeamPolicy` beside the other
team-scoped abilities rather than in a policy of their own. The header returns
the same team-wide numbers to every member; only `identity.user` is the
caller. The players endpoint is the one that shapes its body by role.

### The session pool

Both endpoints analyze the N most recent **`analysis_ready`** sessions for the
team. Nothing earlier in the lifecycle has a complete timeline. N comes from
`?sessions=`, an integer validated to `1..50`, default `3`.

"Most recent" orders by `app_sessions.analysis_ready_at` descending, nulls
last, `id` descending as the tie-break. The column is new:

- Nullable `timestamp`, added after `status`.
- `Session::markAnalysisReady()` stamps it `now()` alongside the status write.
- `reopenReview()` leaves it in place. It records when the session **last
  reached** `analysis_ready`, not whether it is there now.
- The migration backfills existing `analysis_ready` rows to `updated_at`, the
  closest available proxy. No backfill for any other status.

### The insufficient-sessions guard

When the team has fewer than N `analysis_ready` sessions the data blocks
collapse to a message and only the always-on blocks render. Equivalent to
`pool->count() < N`, since the pool is capped at N.

- header `kpi` -> `{ "message": "Insufficient sessions queried for KPI of Communication" }`
- header `comm_mix` -> `{ "message": "Insufficient sessions queried for Communication Mix" }`
- players body -> `{ "window": {...}, "message": "Insufficient sessions queried for Player Stats" }`, no `players` / `you` / `team_median`

The header `identity` and `window` blocks always render. Exactly N renders
normally.

### Pooled, not averaged

Rates pool: sum the numerators over the sum of the denominators across the pool.
Counts are plain sums. A four-minute session and a forty-minute session
contribute in proportion to their length, which a per-session mean would not do.
The arithmetic reuses `App\Support\TimelineMetrics` (`frequencyPerMin`,
`commEventCounts`, `redundantCount`, `alignmentCounts`) and `Session::windowMs()`
per session.

### `GET /dashboard/header`

- `identity` -- `{ team: TeamResource, user: UserResource, analysis_ready_count }`.
  `analysis_ready_count` is the team's all-time `analysis_ready` total,
  independent of `?sessions=`.
- `window` -- `{ sessions_requested, sessions_analyzed, from, to }`. `from` /
  `to` are the earliest and latest `analysis_ready_at` in the pool, null when
  the pool is empty.
- `kpi` -- `{ comm_frequency, alignment_rate, absence_ms, calls_classified }`.
  - `comm_frequency` -- pooled events per minute over the summed window.
  - `alignment_rate` -- `(assessed_total - possibly_negative) / assessed_total`
    as a percent, two decimals, over the pooled `game_state_alignment`
    annotations. Null when nothing in the pool was assessed. This is a
    valence-derived proxy, **not** an accuracy verdict: ADR 0008 is explicit
    that the assessment is valence, and the field is deliberately not named
    `accuracy`. A `possibly_negative` reading is the only one that lowers it.
  - `absence_ms` -- summed dead-air-period duration.
  - `calls_classified` -- total communication events.
- `comm_mix` -- `{ informative, declarative, compound, redundant, absence, calls_classified }`,
  all counts over the pool. `redundant` is the `is_redundant` tally, a flag
  over `calls_classified`, not a fourth type. `absence` is the dead-air-period
  **count** (the duration is `kpi.absence_ms`). `calls_classified` repeats so
  the card stands alone and equals `informative + declarative + compound`.

### `GET /dashboard/players`

`window` as above. The body then depends on the caller's team role.

**Coach** -> `players`, one line per active non-coach member (coaches record no
communication events; excluded by live team role). Empty roster is `players: []`,
not the insufficient message.

```
{ user_id, username, is_online, comm_frequency, alignment_rate, calls_logged, sessions_played }
```

A line pools that member's **own** communication events over the pool sessions
where they have a `completed` transcript. `comm_frequency`'s denominator is the
sum of those transcripts' `audio_duration_ms`. `alignment_rate` uses the same
formula as the header, over that member's events. `sessions_played` counts those
sessions. A member with no completed transcript anywhere in the pool is
`comm_frequency: 0`, `alignment_rate: null`, `calls_logged: 0`,
`sessions_played: 0`.

**Player** -> `you` + `team_median`, no roster.

- `you` -- `{ user_id, comm_frequency, alignment_rate, calls_logged }`, the same
  per-player computation minus `is_online` and `sessions_played`.
- `team_median` -- `{ comm_frequency, alignment_rate, calls_logged }`, the
  median of each metric across the roster lines, computed independently per
  metric. Members with `sessions_played == 0` are out of the population; for
  `alignment_rate`, members whose own value is null additionally drop out of
  that metric. A population below two yields null. `DashboardMetrics::median()`
  is the pure helper (middle value for an odd count, mean of the two middle for
  an even count, null for empty).

A coach never sees `team_median`; a player never sees the roster.

### Out of scope

Per-player "absence". Dead air is team-wide by definition (ADR 0009, CONTEXT.md
"evaluated across the whole team's combined audio, not per player"), and there
is no per-player absence entity. The player `you` / `team_median` card carries
three metrics, not four. Adding a personal-silence metric would be its own ADR.

## Consequences

- One more nullable column on `app_sessions`, and `markAnalysisReady()` now
  writes two fields.
- The header eager-loads, per pooled session, its communication events with
  their annotations plus its dead-air periods; the players endpoint also loads
  participants and transcripts. Capped at 50 pooled sessions, this is a coach
  loading a dashboard occasionally, so the N+1 across the pool is accepted for
  the prototype rather than folded into one query.
- `alignment_rate` is a new phrasing of the ADR 0008 assessment. The valence
  caveat is carried in the field name and this ADR, not enforced in code.
