# 6. Communication events, the timeline_ready transition, and the timeline read endpoints

ADR 0004 deferred the design of `comm_events` and left a completed session in
`processing` with no coded exit. This milestone builds keyword-based
communication-event detection, the transition out of `processing`, and the read
endpoints for a session whose timeline is ready.

The target ER diagram nests `TIMELINE -> TIMESTAMPS -> EVENTS ->
(COMM_EVENTS | GAME_EVENTS)`, with `COMM_EVENT_WORDS` and `CALLOUT_DETECTIONS`
as children of `COMM_EVENTS`. A Timestamp is one marked interval on a Timeline;
a Communication Event is one kind, a Game Event (Riot) is another and is
deferred.

## Decision

### What a communication event is

One Communication Event per **cluster** of Team Keyword hits that fall within
`comm_event_padding_ms` of one another (measured end-of-one-hit to
start-of-next) in a single player's transcript. A lone hit is a cluster of one.
The event span is the first hit's `start_ms` to the last hit's `end_ms`.

`communication_type` is derived from the Categories of the hits in the cluster:

- all Informative -> `informative`
- all Declarative -> `declarative`
- both present -> `compound`

`compound` is a property of the event, not a keyword Category. The keyword
taxonomy stays two-valued (see `CONTEXT.md` — **Category**).

`is_redundant` is set when a cluster repeats a Category or the same normalized
keyword with no new information. This is **within-player** redundancy only.
Cross-player redundancy ("a teammate already called it") needs the offset-correct
merged timeline and is deferred.

### Matching

Case-insensitive exact match of a Team Keyword against a transcript token with
surrounding punctuation stripped. No stemming — ADR 0001 made keywords single
salient words and deferred fuzzy matching. No confidence floor; each hit stores
its word confidence so a coach or the frontend can filter later without
re-running detection.

### Tables

Flattened, not the diagram's three-level nest:

- `comm_events` — `transcript_id`, `communication_type`
  (`informative` / `declarative` / `compound`), `is_redundant`, `start_ms`,
  `end_ms`, `content` (the text span), `padding_ms` (the value used, snapshot),
  timestamps.
- `callout_detections` — `comm_event_id`, `transcript_word_id`, `keyword`
  (snapshot), `normalized_keyword`, `category` (snapshot,
  `informative` / `declarative`), `start_ms`, `end_ms`, `confidence`. One row per
  keyword hit.

This contradicts the ER diagram's `TIMELINE -> TIMESTAMPS -> EVENTS ->
COMM_EVENTS` nesting, collapsed for the same reason ADR 0004 flattened transcript
storage: only one event kind is built, so the intermediate `timestamps` and
`events` supertype tables would be speculative surface. The `session_timeline`
endpoint's `type`-tagged list preserves the conceptual grouping. When Game Events
are built, that milestone adds `type: "game_event"` entries and whatever shared
spine it needs.

`comm_event_words` from the diagram is not built: the event carries its span and
its `transcript_id`, so the words inside it are a query against
`transcript_words`, not a copy.

### Detection job

`DetectCommEvents`, one per transcript, dispatched after
`FetchTranscriptSentences` (ADR 0005) commits. Full per-AOD chain:
`SubmitTranscription -> PollTranscription -> FetchTranscriptSentences ->
DetectCommEvents -> AdvanceSessionAfterProcessing`.

A dedicated job (rather than folding detection into `FetchTranscriptSentences`)
keeps it independently re-runnable — keyword re-tuning is a foreseeable
follow-up — and lets the clustering logic be unit-tested in isolation.

### The padding setting

A fourth Team Settings row, `comm_event_padding_ms`, integer milliseconds,
default 2000, one window used for both compound-pairing and redundancy. It is
snapshot onto each `comm_events` row when detection runs. Editing it never
re-runs detection on an already-processed session, matching the existing Team
Settings rule.

### The timeline_ready transition

`AdvanceSessionAfterProcessing`, an idempotent job dispatched as each transcript
reaches a terminal state. It flips the session `processing -> timeline_ready`
once **every** transcript is terminal and every non-`failed` transcript has its
communication events detected. A permanently `failed` transcript counts as done —
the session advances with a degraded timeline rather than stalling on one bad
mic. The failed count stays visible through the team session index aggregate. The
existing `SessionStatusChanged` broadcast is reused.

Leaving `processing` requires captions and communication-event timestamps under
the zero-offset assumption, nothing more. Riot match data and real per-recording
offset mapping stay deferred and belong to `annotating`, not a `processing` gate;
gating on them would mean `processing` could never end with today's inputs.

### Read endpoints

All three are gated: 409 while `processing`, served from `timeline_ready`.
`GET /sessions/{id}` also starts succeeding again at `timeline_ready`.

**`GET /sessions/{session}/captions`** — defined in ADR 0005. The
`timeline_ready` gate lands here.

**`GET /sessions/{session}/timeline`** (`session_timeline`) — the rich
post-processing view:

```
{
  "session_id": 1,
  "status": "timeline_ready",
  "timeline": { "id": 1, "created_at": "...", "duration_ms": null },
  "timestamps": [
    {
      "type": "communication_event",
      "id": 7,
      "participant_id": 10,
      "user_id": 42,
      "communication_type": "compound",
      "is_redundant": false,
      "start_ms": 41200,
      "end_ms": 43800,
      "content": "planting rotating",
      "callouts": [
        { "keyword": "planting", "normalized_keyword": "planting", "category": "informative", "start_ms": 41200, "end_ms": 41600, "confidence": 0.91 }
      ]
    }
  ],
  "participants": [
    {
      "participant_id": 10,
      "user_id": 42,
      "transcript": { "id": 3, "status": "completed", "language_code": "en", "confidence": 0.94, "audio_duration_ms": 812000 },
      "aod": { "id": 5, "original_filename": "...", "mime_type": "audio/webm", "size_bytes": 12345678 },
      "vod": { "id": 6, "original_filename": "...", "mime_type": "video/webm", "size_bytes": 987654321 }
    }
  ]
}
```

`timestamps` is ordered by `start_ms` and currently only ever holds
`type: "communication_event"`. Caption sentences are **not** embedded — they are
large and stay in `GET /sessions/{session}/captions`. Recordings are **metadata
only**; playback is a later `GET /recordings/{recording}` endpoint (signed URL or
range-request streaming), its own small issue.

**`GET /sessions/{session}/timeline-summary`** (`timeline_summary`) — the
aggregate:

```
{
  "session_id": 1,
  "session_window_ms": 812000,
  "team": {
    "frequency_per_min": 3.4,
    "counts": { "informative": 20, "declarative": 14, "compound": 6, "redundant": 3, "total": 40 },
    "total_talk_ms": 240000,
    "total_silence_ms": 572000,
    "longest_silence_ms": 61000
  },
  "participants": [
    { "participant_id": 10, "user_id": 42, "frequency_per_min": 0.9, "counts": { "informative": 5, "declarative": 3, "compound": 1, "redundant": 1, "total": 9 } }
  ]
}
```

`session_window_ms` is the max transcript `audio_duration_ms` (zero-offset).
`frequency_per_min` is communication events per minute over that window.
`total_silence_ms` / `longest_silence_ms` are a **provisional proxy**, computed
team-aggregate (any player talking = not silent) from communication-event spans.
The real Dead Air feature — threshold-driven, cross-referenced against Riot game
events, producing system annotations — stays its own milestone; these field
names are marked provisional.

## Considered options

- **One communication event per keyword hit, then a second pass linking
  Informative + Declarative hits into Compound.** Rejected. Clustering yields
  Compound and Redundant in a single pass with no second structure.
- **One communication event per sentence containing a hit.** Rejected. A sentence
  can hold two unrelated callouts, and a callout can straddle a sentence
  boundary. The padding window is the domain-meaningful unit.
- **Build the ER diagram's `timestamps` / `events` supertype tables now.**
  Rejected. Only one event kind exists; the discriminated list in the endpoint
  carries the grouping until Game Events need a shared spine.
- **Fold detection into `FetchTranscriptSentences`.** Rejected. A separate job is
  independently re-runnable for keyword re-tuning and unit-testable in isolation.
- **Block `timeline_ready` on Riot data and real offsets.** Rejected. Neither
  exists yet; `processing` would never end.
- **Stall the session in `processing` when a transcript fails.** Rejected. One
  dead mic must not freeze the whole review. The failure is terminal and its
  reason is stored on the transcript.
- **Real dead-air detection in `timeline_summary`.** Rejected. It is a milestone
  of its own (team-aggregate windows, threshold, Riot cross-reference). The proxy
  gives the frontend a number without pulling that forward.
- **Embed caption sentences in the timeline response.** Rejected. Large payload;
  the caption UI fetches them on demand from its own endpoint.

## Consequences

- A session now leaves `processing` on its own. A session stuck in `processing`
  is a bug, not the expected prototype behaviour ADR 0004 described.
- `timeline_summary` silence numbers will change when real Dead Air detection
  lands. The provisional field names signal that.
- `comm_event_padding_ms` joins the "editing settings never re-runs analysis"
  rule. A re-detection path (for keyword or padding edits) is a follow-up,
  alongside the transcript re-run path ADR 0004 also deferred.
- Cross-player redundancy, phrase matching, stemming, Game Events, and
  coach-authored review-point timestamps all remain out of scope.
- `GET /sessions/{id}` succeeds again at `timeline_ready`; clients must handle
  the session view reappearing after the 409 window.
