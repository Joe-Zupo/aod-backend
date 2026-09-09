# 9. Dead-air detection

> Related: ADR 0006 deferred this as its own milestone. ADR 0008 built the
> `annotations` table and the first `AdvanceSessionAfterProcessing` fan-in
> clause; this ADR adds the second.

ADR 0006 shipped a provisional silence proxy on `timeline_summary`
(`talk_percentage`, `silence_percentage`, `longest_silence_percentage`),
computed from communication-event spans, and named real dead-air detection as a
separate milestone. This is that milestone.

Dead air is the absence of communication. It is a different axis from game-state
alignment (ADR 0008), which is about communication that is present, so it was
split from issue #14 on purpose. It is not gated on game events, it owns its own
persistence, and it changes the timeline's shared spine.

## Decision

### What a dead-air period is

A team-aggregate silent stretch. Any player talking breaks silence. The input is
every `comm_events` row across every one of the session's transcripts, flattened
to `[start_ms, end_ms]` spans and unioned into a sorted, disjoint set by
`App\Support\Intervals::merge`. `TimelineMetrics::silenceProxy` is refactored
onto the same helper so the two silence computations cannot drift.

A period is any gap strictly longer than the team's `dead_air_threshold`:

- between two consecutive merged spans,
- between the window start (0) and the first span,
- between the last span and the window end.

The session window is `max(audio_duration_ms)` over the completed transcripts,
the same window `timeline_summary` already uses. A session with no comm events
at all is one period covering the whole window.

The threshold is a minimum-gap test and nothing else. It is never a merge
distance, so a period never contains a callout.

`App\Support\DeadAirDetector::periods($spans, $windowMs, $thresholdMs)` is the
pure logic, unit-tested beside `CommEventClusterer`, returning
`list<array{start_ms: int, end_ms: int}>`.

### The `dead_air_periods` table

Every period is persisted, whether or not it gets an annotation:

- `dead_air_periods`: `session_id` (FK, cascade on delete), `start_ms`,
  `end_ms`, `dead_air_threshold_ms` (the value used, snapshot on each row like
  `comm_events.padding_ms`), timestamps, index on `session_id`.

`duration_ms` is not stored. It is `end_ms - start_ms`, computed where it is
read.

No change to `annotations`. The ADR 0008 amendment already made it polymorphic
for exactly this. A `dead_air` annotation attaches to a `dead_air_periods` row
through `annotatable_type` / `annotatable_id`, FQCN `App\Models\DeadAirPeriod`,
no morph map. There is no database cascade from a period to its annotations, so
`DetectDeadAir` and `Session::reanalyze` delete them explicitly.

### When a period gets an annotation

Only when a game event falls strictly inside it, `start_ms < match_time_ms <
end_ms`. An event exactly on an edge is adjacent to a real callout and belongs
to it. A period with no interior event is a row with no annotation, visible on
the timeline as a gap, with nothing asserted about it.

The annotation:

- `topic` `dead_air`, `user_id` null, `assessment` null, `alignment_window_ms`
  null.
- `game_event_ids`: the interior events by `match_time_ms` ascending.
- `body`: `"<Ns> of team silence; <phrases> went uncalled. Consider reviewing
  this moment."` The duration is trimmed seconds (`8500` reads `"8.5s"`). Each
  event is `"<clause> <offset> in"`, offset being `match_time_ms -
  period.start_ms` rendered `"1.1s in"` or `"400 ms in"`. The clause is the same
  phrasing game-state alignment uses (`"enemy spike_plant"`, `"Jett died"` with
  a name pulled from the event `note` or `raw`, `"an ally kill"` when the feed
  named nobody). Capped at three events, then `"and N more"`, joined with
  `"; "`. The review nudge is always present, because a dead-air annotation only
  exists when something happened during the silence.

`App\Support\GameEventNarrator` is extracted from `GameStateAlignmentAssessor`,
which is refactored onto it with no behaviour change. It owns `seconds`, the
player-name lookup, the per-event clause, and the cap-then-"and N more" join.
Each feature wraps its own offset wording around `clause`.

### The job and the second fan-in clause

`DetectDeadAir`, one per session. `tries`, `backoff` and `failed` match
`AssessGameStateAlignment` as it stands after the issue #14 review.

`handle` returns unless the session is `processing`. It opens one transaction
that locks the session row, re-reads `dead_air_detected_at` and returns if it is
already set, deletes this session's `dead_air_periods` and their annotations,
inserts the fresh periods, writes the annotations, and sets the marker. It then
re-dispatches `AdvanceSessionAfterProcessing`.

`failed` degrades rather than stranding the session. It sets the marker with no
periods and re-dispatches `AdvanceSessionAfterProcessing`. `DetectDeadAir` gates
every session, and there is no reanalyze path out of `processing`, so a
permanent failure with no handler would freeze the pipeline for every session
type.

`AdvanceSessionAfterProcessing` gains a clause beside the alignment one. Once
every transcript is terminal and every completed transcript's comm events are
detected, a session with a null `dead_air_detected_at` dispatches `DetectDeadAir`
and does not advance. When both markers are null the pass dispatches both jobs.
They write disjoint tables, so order does not matter, and whichever finishes
last sees both markers set and advances the session.

The alignment job gets the same lock-and-recheck retrofit. The issue #14 review
found that the fan-in can dispatch a follow-up job more than once when
transcripts finish close together, and that concurrent runs could duplicate
rows. Re-reading the marker under the session lock and bailing if it is set
closes that window for both jobs without a cache-lock dependency, so
`ShouldBeUnique` is not needed.

`Session::reanalyze` nulls `dead_air_detected_at` and deletes this session's
`dead_air_periods` and their annotations in the transition transaction, so a
re-analyzed session recomputes them. `DetectCommEvents` does not touch dead-air
state, which is session-level, not per comm event.

### Timeline surfacing

The top-level `game_events` array on `session_timeline` becomes the shared
chronological spine ADR 0008 anticipated. It carries two entry types:

- `{ type: "game_event", ... }`, a point in time, unchanged.
- `{ type: "dead_air", id, start_ms, end_ms, duration_ms, annotations }`, a real
  interval. `annotations` uses the existing `TimelineAnnotationResource`
  (`topic`, `assessment`, `body`, `game_event_ids`), and is `[]` when the period
  has no interior event.

Entries are merged and sorted by `start_ms` ascending, ties putting `game_event`
before `dead_air`. Dead-air entries appear here only, never under
`data.participants[]`, because dead air is team-aggregate. A small
`App\Support\TimelineSpine` owns the merge, the sort and the tie-break so they
are unit-tested.

The array keeps the name `game_events` even though it now holds both types.
Renaming it is a breaking change for a key clients already read, and the value
is still the session-wide spine. The mixed-type name is a known wart.

`timeline_summary` gains `dead_air: { count, longest_ms }` on the `team` block,
via `TimelineMetrics::deadAirCounts`. `count` is every persisted period,
annotated or not. `longest_ms` is the longest `end_ms - start_ms`, or 0. There
is no per-participant dead-air block.

## Considered options

- **`dead_air_threshold` as a merge distance as well as a minimum gap.**
  Rejected. It overloads one setting with two meanings and lets a period contain
  callouts, which contradicts "silence".
- **Exclude the leading and trailing window gaps.** Rejected. A silence from the
  recording start to the first callout with a game event inside it (the round
  opened and nobody said anything) is exactly the signal this feature is for,
  and excluding the edges makes a fully silent session produce no periods, which
  is backwards.
- **Rows in the `game_events` table rather than a dedicated table.** Rejected. A
  dead-air period is not a game event, and "an annotation never attaches to a
  game event" stays a clean rule only if periods are their own entity.
- **One combined detection job for alignment and dead air.** Rejected. Dead air
  runs for every session and alignment only for sessions with game events, they
  have different failure semantics, and two markers keep the fan-in legible.
- **A per-participant dead-air breakdown.** Rejected. Dead air is the team going
  quiet. One player silent while a teammate calls is not dead air.
- **Retire the `timeline_summary` silence proxy in this batch.** Deferred. The
  proxy and the real periods measure nearly the same thing and the proxy has
  live clients. Removing it is a follow-up once the frontend has moved.
- **Rename the `game_events` timeline key.** Rejected for now. Breaking, for a
  cosmetic gain.
- **`ShouldBeUnique` on the processing jobs.** Rejected. The lock-and-recheck
  achieves the same guarantee against duplicate rows without depending on a
  lock-capable cache store.

## Consequences

- `timeline_ready` now waits on dead-air detection for every session, not just
  sessions with game events. A session stuck in `processing` with its
  transcripts done points at `DetectDeadAir` or `AssessGameStateAlignment` or
  their re-dispatch.
- `session_timeline`'s `game_events` array is now heterogeneous. Clients that
  assumed every entry has `match_time_ms` and no duration need to branch on
  `type`.
- Two silence representations ship together, the proxy percentages and the real
  periods. They will not agree exactly, since the proxy has no threshold. The
  proxy field names stay marked provisional and their removal is the named
  follow-up.
- `GameStateAlignmentAssessor` and `TimelineMetrics::silenceProxy` are
  refactored onto shared helpers (`GameEventNarrator`, `Intervals::merge`) with
  no behaviour change. Their existing tests are the guard.
- A re-detection or re-narration path for a `dead_air_threshold` edit is not
  built, matching every other detection setting: editing it does not re-run
  analysis on an already-processed session.
- The `annotations` table gains its second topic and its second `annotatable`
  type with no schema change, as the ADR 0008 amendment intended.
