# 7. Manual game-event ingest on session completion

The framework's third insight axis, communication against game state, needs game
events (kills, deaths, spike plants/defuses, round outcomes) on the Timeline. The
target design pulls those from the Riot API. That access is not secured, and the
prototype has to demonstrate one end-to-end use case before it is.

## Decision

### A manual game-event payload, supplied at completion

`POST /sessions/{session}/complete` gains an optional `game_events` field: a
JSON-encoded string in the multipart body, alongside `players[]`. It is a
hand-authored dev artifact for the prototype's prepared use case, not a
production input.

Each element:

```
{
  "type": "kill" | "death" | "spike_plant" | "spike_defuse" | "round_win" | "round_lost",
  "side": "ally" | "enemy",          // required for kill/death/spike_plant/spike_defuse; omitted for round_win/round_lost
  "match_time_ms": <int >= 0>,        // from match start, which equals session start under the zero-offset assumption
  "round_number": <int>,             // optional, stored, unused by alignment v1
  "note": <string>                   // optional, stored, unused by alignment v1
}
```

`side` is the team the event favours, not the team that performed the action:
`kill` + `enemy` means our side lost someone. Valence, owned in code and
unit-tested:

- **favourable**: `kill` / `spike_plant` / `spike_defuse` with `side: ally`;
  `round_win`
- **unfavourable**: `kill` / `spike_plant` / `spike_defuse` with `side: enemy`;
  `death` with `side: ally`; `round_lost`
- **neutral**: everything else

### Validation is all-or-nothing

`CompleteSessionRequest` validates `game_events` structurally: absent, or valid
JSON whose every element matches the schema (`type` in the enum, `side` present
unless a round outcome, `match_time_ms` a non-negative integer). Any violation
returns 422 and nothing is stored — the call already validates the recording
roster strictly, and a half-accepted game log is worse than making the author fix
the JSON. The controller decodes the payload and passes `?array $gameEvents` into
`Session::complete($entries, $gameEvents)`, which writes the rows inside the same
transaction that stores recordings and queues transcripts.

There is no correction path in this issue. A wrong-but-valid log stays for the
life of the session. A `PATCH /sessions/{session}/game-events` before
`timeline_ready` is a noted follow-up.

### Storage

One flat table, following ADR 0006's rejection of the ER diagram's
`TIMESTAMPS -> EVENTS -> GAME_EVENTS` nesting:

- `game_events` — `session_id` (FK, cascade), `source` (`manual` | `riot`,
  default `manual`), `type`, `side` (nullable), `match_time_ms` (unsigned big
  int), `round_number` (nullable unsigned int), `note` (nullable string), `raw`
  (nullable json), timestamps. Index `[session_id, match_time_ms]`.

Keyed to `session_id`, not `timeline_id`, matching how `comm_events` keys
`transcript_id` rather than the Timeline.

### How it surfaces

`GET /sessions/{session}/timeline` (`session_timeline`, ADR 0006) synthesises
`type: "game_event"` entries into its `timestamps` array, interleaved with
`type: "communication_event"` by `start_ms`. A game event is a point in time, so
`start_ms == end_ms == match_time_ms`.

```
{
  "type": "game_event",
  "id": 4,
  "game_type": "spike_plant",
  "side": "ally",
  "match_time_ms": 41000,
  "start_ms": 41000,
  "end_ms": 41000,
  "round_number": 3,
  "note": null
}
```

`GET /sessions/{session}/timeline-summary` gains a team-level
`game_events: { total, by_type: { kill, death, spike_plant, spike_defuse, round_win, round_lost } }`.

Both stay behind the existing gate: 409 while `processing`, served from
`timeline_ready`.

## Considered options

- **A separate `POST /sessions/{session}/game-events` endpoint callable during
  `processing`.** Rejected here in favour of a completion slot, which keeps the
  data with the recording it describes and needs no new route or status guard.
  The tradeoff is a bad payload fails the whole completion call; that is the
  intended behaviour (see validation above).
- **`side` as the team that performed the action.** Rejected. It makes `death`
  nearly redundant with `kill` of the other side, and the thing alignment needs
  is which team the event was good for.
- **Free-form `action` string with a coach-set `valence`.** Rejected. A fixed
  enum with code-owned valence is consistent and testable, and the prototype's
  event vocabulary is known (the flowchart's Kill / Death / Spike Plant / Spike
  Defuse, plus round outcomes).
- **A `riot_matches`-equivalent metadata row (map, duration, version).**
  Rejected for now. The prototype needs the events, not match metadata; the
  Timeline's `duration_ms` stays null as ADR 0004 left it.
- **Storing game events as first-class `timestamps` rows.** Rejected, same as
  ADR 0006: only two event kinds exist and the endpoint's `type` field carries
  the grouping.

## Consequences

- `Session::complete()` gains a second parameter and a new failure mode (422 on a
  malformed game-event payload) that its tests must cover.
- `game_events.source` makes the Riot swap an ingest-only change: a future
  importer writes `source: "riot"` rows into the same table and everything
  downstream (the timeline endpoint, ADR 0008's alignment) reads them unchanged.
  The manual path survives as a fallback and override.
- A session with no `game_events` is unaffected: no `game_event` timestamps, and
  ADR 0008's alignment step is skipped.
- `match_time_ms` rides on the zero-offset assumption (ADR 0004). A real match
  clock offset is the Timeline milestone's problem, not this one.

## Amendment, 2026-09-03, issue #13 implementation

Four points settled during implementation, all narrowing the decision for the
prototype rather than reversing it.

### `game_events` is required on the completion call

The prototype's one prepared use case always carries game events, so completion
without them is refused. Presence is enforced in `SessionController::complete()`,
after `authorize()` but before `Session::complete()`, as a 422 with the message
`Game events are required to complete a session.` (empty array included). It is
**not** a `CompleteSessionRequest` rule. It does run ahead of the roster and
status checks inside `Session::complete()`, so a request that both omits
`game_events` and has a bad roster is told about `game_events` first; that is
an accepted tradeoff for keeping the guard out of the completion transaction.
Shape validation stays in `CompleteSessionRequest`.
`prepareForValidation()` accepts `game_events` either as a JSON string or as an
uploaded `.json` file (the fixture `docs/agents/game-event-ingest.md` produces
is a file), normalises both to a decoded array, and then the element rules
apply all-or-nothing. `Session::complete(array $entries, array $gameEvents =
[])` keeps the default, so model-level callers and their tests are unaffected.
This supersedes the "a session with no `game_events` is unaffected" consequence
above for the HTTP path.

### `round_number` is required only in the authoring grammar

Every event block in `docs/agents/game-event-ingest.md` must carry a `round`
line, but `round_number` stays `nullable` in the endpoint schema and the table,
so a future Riot importer is not forced to supply it.

### `side` on a round outcome is rejected

`prohibited_if` on `game_events.*.side` when the type is `round_win` or
`round_lost`. A contradictory payload fails 422 rather than having `side`
silently stripped.

### Timeline surfacing is a top-level `game_events` list

The "How it surfaces" section assumed one top-level `timestamps[]` with game
and communication events interleaved. Communication-event timestamps have since
moved under `data.participants[].timestamps[]` (per-participant grouping). Game
events are session-level, so `GET /sessions/{session}/timeline` carries them as
a separate top-level `data.game_events[]`, ordered by `match_time_ms`, each
entry shaped exactly as the `type: "game_event"` block above. There is no
interleaved list, and `data.game_events` is `[]` when the session has none.
`timeline-summary`'s `team.game_events` aggregate is unchanged from the
decision.
