# 8. Game-state alignment annotations for communication events

With Communication Events (ADR 0006) and Game Events (ADR 0007) both on the
Timeline, the framework's third axis can be produced: for each callout that makes
a checkable game-state claim, does a nearby game event corroborate or contradict
it. The result is a system-generated Annotation on the communication event.

This is descriptive correspondence, not a coaching verdict. The thesis is
explicit that the tool does not judge communication quality — that reading stays
with the coach. A callout followed by a lost fight is not "inaccurate"; the
caller may have been right. Alignment only reports whether the game state near a
callout matched what the callout was about.

## Decision

### The Annotation entity

One `annotations` table, the shared home for system annotations now and coach or
player free-text later:

- `annotations` — `comm_event_id` (FK, cascade — the primary attach), `user_id`
  (nullable FK, `NULL` = system-generated), `topic` (string, e.g.
  `game_state_alignment`), `assessment` (nullable, `accurate` / `inaccurate` /
  `neutral`, set only for alignment topics), `body` (text), `game_event_ids`
  (json array, the events that drove the assessment), timestamps.

Attach is `comm_event_id` only. A polymorphic or `game_event_id` attach is a
follow-up for when game events carry annotations. `assessment` is nullable so a
later `coach_note` annotation shares the table cleanly.

### What gets assessed

Only Communication Events whose keyword maps to a game-event kind (see the map
below). A callout with no game-state claim ("mid", "rotate") gets no annotation —
there is nothing to be accurate about. An assessed event resolves to:

- `accurate` — a game event of the mapped kind occurs within the alignment
  window.
- `inaccurate` — a game event from the mapped kind's contradiction pair occurs
  within the window.
- `neutral` — the claim was checkable but neither occurred in the window.

### Keyword to game-event-kind map

Static, in code, covering the default keyword lists. A keyword not in the map
carries no claim.

| keyword (normalized) | mapped kind |
| --- | --- |
| `planting`, `planted`, `plant` | `spike_plant` |
| `defusing`, `defused`, `defuse` | `spike_defuse` |
| `down`, `dead`, `killed`, `traded`, `frag` | `kill` |
| `died`, `lost`, `lost-someone` | `death` |

Per-team keyword tagging (an `implies_event_type` column on `team_keywords`) is
the planned extension when custom keywords need alignment. Out of scope here.

### Contradiction pairs

Kept deliberately narrow to unambiguous mutually-exclusive game states:

- a `spike_plant` claim contradicted by `spike_defuse` + `side: ally`
- a `spike_defuse` claim contradicted by `spike_plant` + `side: enemy`

No pair for `kill` / `death` claims — a "down" callout near an ally death is not a
contradiction, and treating it as one drifts back to outcome-judgment.

### The window

Symmetric, `+/- game_alignment_window_ms` around the communication event span. A
callout can both describe a just-happened event and precede its consequence, and
correspondence does not care about causal direction.

`game_alignment_window_ms` is a Team Settings row, default 5000 — game-event
timing is coarser than callout clustering, so it does not borrow
`comm_event_padding_ms` (2000). Snapshot at assessment time, like the other
detection settings; a later edit does not re-run assessment on an already-processed
session.

### Multiple events in the window

One annotation per assessed communication event. When both a corroborating event
of the mapped kind and a contradiction-pair event fall in the window, the one
**nearest** the communication event span wins, ties broken by earlier
`match_time_ms`. Game events of an unrelated kind are ignored for the assessment.
`game_event_ids` lists every corroborating and contradiction-pair event in the
window, the deciding one first; a `neutral` assessment has an empty
`game_event_ids`.

### The job and the timeline_ready gate

`AssessGameStateAlignment`, one per session, writes the `annotations` rows.
`AdvanceSessionAfterProcessing` (ADR 0006) owns the dispatch: when it sees
communication-event detection complete for every transcript, the session has
`game_events`, and no assessment has run, it dispatches
`AssessGameStateAlignment` and returns without advancing. That job re-dispatches
`AdvanceSessionAfterProcessing` on completion, which then finds the assessment
done and moves the session to `timeline_ready`. A session with no `game_events`
skips alignment and advances exactly as it does today.

The fan-in check becomes: every transcript terminal, every transcript's
communication events detected, and (session has `game_events` -> alignment
assessed).

### Read endpoint changes

`GET /sessions/{session}/timeline` embeds the alignment annotation inline on each
assessed `communication_event` entry:

```
{
  "type": "communication_event",
  "id": 7,
  ...,
  "annotations": [
    {
      "topic": "game_state_alignment",
      "assessment": "accurate",
      "body": "Callout mapped to spike_plant; ally spike_plant 900 ms later.",
      "game_event_ids": [4]
    }
  ]
}
```

`GET /sessions/{session}/timeline-summary` gains `alignment: { accurate,
inaccurate, neutral, assessed_total }` at team and per-player level. Counts are
raw; any rate is left to the frontend — a ratio over a prepared-data denominator
is misleading as a headline.

## Considered options

- **Outcome-based assessment** (`accurate` if a favourable game event follows,
  `inaccurate` if an unfavourable one does). Rejected. It turns the tool into the
  quality judge the thesis says it must not be, and mislabels a correct callout
  followed by a death.
- **Rename the values to `corroborated` / `contradicted` / `unmarked`.**
  Considered; the chosen vocabulary is `accurate` / `inaccurate` / `neutral` per
  the milestone's framing, with the ADR text carrying the "descriptive, not a
  verdict" caveat.
- **Assess every communication event, `neutral` when nothing maps.** Rejected. An
  annotation on a callout that makes no claim is noise; unassessed (no row) is
  the honest state.
- **A broad contradiction model.** Rejected for v1 in favour of the two
  spike-state pairs, which are genuinely mutually exclusive.
- **A dedicated `alignment_assessments` table instead of `annotations`.**
  Rejected. Annotations are one shared entity (system and human), primarily on
  communication-event timestamps, per the ER diagram and the milestone
  discussion.
- **Pivot table for the game-event link.** Rejected. A json `game_event_ids`
  column suits a read-mostly reference to a handful of events, never queried from
  the game-event side in v1.
- **`game_alignment_window_ms` as a fixed constant.** Rejected. It joins the
  other snapshot-at-analysis detection settings so a team can tune it without a
  deploy.

## Consequences

- `timeline_ready` now also waits on alignment whenever a session has game
  events. A session stuck before `timeline_ready` with game events present points
  at `AssessGameStateAlignment` or its re-dispatch.
- The keyword map is static, so a team using non-default informative keywords for
  spike or frag callouts gets `neutral` (unassessed) until the `team_keywords`
  extension lands.
- `annotations` is introduced with one writer (the alignment job) and one attach
  point; the coach and player annotation features extend it rather than add their
  own table.
- Alignment output shifts if the keyword map, the pairs, or the window change; a
  re-assessment path is a follow-up alongside the re-detection and transcript
  re-run paths already deferred.

## Amendment, 2026-09-07, issue #14 implementation

Settled during implementation and a follow-up review. Two of these reverse
wording in the decision above: the assessment values, and what gets a row.

### The `annotations` table is polymorphic

The attach is `annotatable_type` / `annotatable_id` (`morphs`), not the
`comm_event_id` foreign key the decision named. Game-state alignment attaches to
a `CommEvent`; dead-air correspondence (`docs/adr/0009`) will attach to a
dead-air period through the same column pair; coach and player notes extend it.
`user_id` is a nullable FK — null on every row today (system-generated), set
once timeline management lets a coach or player author one. There is no database
cascade (polymorphic), so `DetectCommEvents` and `Session::reanalyze()` delete a
comm event's alignment annotations explicitly before its comm events are
rebuilt.

### A row is written for a callout with a nearby game event even if it makes no claim

The decision assessed only mapped-keyword callouts. A row is written when the
keyword maps **or** a game event falls in the window. A callout with neither a
mapped keyword nor a nearby game event gets no row.

### The assessment is soft valence, not correspondence

The values are `possibly_positive` / `possibly_negative` / `neutral`, replacing
`accurate` / `inaccurate` / `neutral`. Every row carries a sign; the same
`App\Support\GameEventValence` reading drives it (favourable ->
`possibly_positive`, unfavourable -> `possibly_negative`, neutral valence such
as an enemy death -> `neutral`). This is `GameEventValence`'s first production
caller.

For a **mapped-keyword** callout:

- The nearest **corroborating** game event (type equals the mapped kind, either
  side) sets the sign through its valence.
- A **contradiction-pair** event (`spike_defuse` + ally against a plant claim,
  `spike_plant` + enemy against a defuse claim) is always `possibly_negative` —
  the callout described a game state the opposite of what happened.
- The nearer of the two decides when both are in the window; ties -> earlier
  `match_time_ms`.
- No corroborating or contradiction-pair event in the window -> `neutral`
  (the claim was checkable and neither happened), even if unrelated events are
  nearby.

For a **narration-only** callout (keyword maps nothing, a game event is in the
window), the sign is the nearest in-window game event's valence, so a bad play
near a callout that made no claim still reads `possibly_negative`. This
deliberately goes past "descriptive correspondence" — the coaching-verdict
caveat below applies more heavily here — and was an explicit call: the nearby
game state is the useful signal even when the words carried no claim.

`side` still does not gate corroboration (a "planted" callout corroborates on a
`spike_plant` of either side); it feeds the valence sign and narrows the
contradiction pairs.

The field now reads as a soft, hedged read of the moment rather than a
correctness verdict; the thesis line that the tool does not judge communication
quality is held by the `possibly_` hedge and the review nudge, both pointing the
coach at the clip rather than pronouncing on it.

### `game_event_ids` and the body

`game_event_ids` lists every in-window game event, not only the relevant ones.
For a mapped-callout decided (`possibly_positive` / `possibly_negative`) row the
deciding event is first, then the rest by absolute distance to the callout span;
a mapped `neutral` and a narration row list the in-window events nearest-first.
This supersedes "a `neutral` assessment has an empty `game_event_ids`".

`body` names the nearby game events with a signed offset ("900 ms later",
"1.2s earlier", "during the callout"), capped at three with "and N more",
pulling a player name from a `kill` / `death` event's `note` or `raw` when the
feed carried one, else "an ally" / "an enemy". Every `possibly_negative` body
ends with `Consider reviewing this moment.` (mapped or narration-only);
`possibly_positive` and `neutral` bodies do not.
`App\Support\GameStateAlignmentMap` is the keyword and contradiction
vocabulary; `App\Support\GameStateAlignmentAssessor` is the pure window /
nearest / valence / body logic, both unit-tested like `GameEventValence` and
`CommEventClusterer`.

### The keyword map ships whole, with inert rows

The full map from the decision is shipped even though only `planted`,
`planting`, `defused`, `defusing`, `down` and `dead` are default keywords. The
`death` row (`died` / `lost` / `lost-someone`) has no backing default keyword
and is inert until a team adds one or the `team_keywords.implies_event_type`
extension lands.

### The gate and the marker

`game_alignment_window` is a fifth Team Settings row (API field
`game_alignment_window_ms`), seeded lazily by `Team::ensureSettings()` at
default 5000, no backfill migration — the `comm_event_padding` pattern. Its
value is snapshot onto each annotation's `alignment_window_ms`.

`AssessGameStateAlignment` is one job per session. It is idempotent (deletes and
rewrites the session's `game_state_alignment` annotations), sets a new
`app_sessions.game_alignment_assessed_at` timestamp, and re-dispatches
`AdvanceSessionAfterProcessing`. The fan-in gains one clause: once every
transcript is terminal and its comm events detected, a session that has game
events and a null `game_alignment_assessed_at` dispatches the job and does not
advance; the job's re-dispatch then finds the marker set and advances to
`timeline_ready`. A session with no game events skips it. `Session::reanalyze()`
nulls the marker and deletes the rows so a re-analyzed session cannot advance on
stale alignment.

### Timeline surfacing

`GET /sessions/{session}/timeline`: each `communication_event` entry under
`data.participants[].timestamps[]` carries an `annotations` array, always
present, `[]` when none, each element `{ topic, assessment, body,
game_event_ids }`. The top-level `data.game_events[]` is unchanged by this
issue; it becomes the shared chronological parent for game events and dead-air
periods in `docs/adr/0009`.

`GET /sessions/{session}/timeline-summary`: `data.team` and each
`data.participants[]` gain `alignment: { possibly_positive, possibly_negative,
neutral, assessed_total }` over the `game_state_alignment` rows. Every alignment
row has an assessment (narration rows included), so all of them count; the
non-null filter only keeps a dead-air row out should one ever share the set.
