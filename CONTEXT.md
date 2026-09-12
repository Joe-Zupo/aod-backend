# AOD Backend

API backend for the Audio-on-Demand (AOD) Communication Analysis framework: esports teams record per-player audio and gameplay video during scrims, transcribe and analyze communication, and cross-reference it against game events for coach-led post-game review.

## Language

**Session**:
A single recorded game (one scrim or match), from lobby through completion. Scope is deliberately narrow for this prototype: one Session always covers exactly one game, never a multi-game block (e.g. a Bo3 or a night of several scrims). Every Session carries a `session_code`, `SESSION_` plus its id zero-padded to at least three digits (`SESSION_048`), assigned on creation and used as its human-facing reference; `session_name` is the free-text title.
_Avoid_: scrim, match, game (when referring to the system entity)

**Timeline**:
The synchronized data spine of a Session, the canonical clock that AOD, VOD, and Riot match data are aligned to via millisecond offsets. Created when the Session enters `processing`, not at Session creation, so a `queuing`, `in_progress`, or `cancelled` Session has none (see `docs/adr/0004-transcription-pipeline.md`). Its Timestamps — Communication Events, and Game Events when supplied — are produced during `processing`; the Session moves to `timeline_ready` once they are complete and any Game-State Alignment has run (see `docs/adr/0006-communication-events.md`, `docs/adr/0008-game-state-alignment.md`).

**Session status**:
A Session's lifecycle state: `queuing`, then `in_progress`, then `processing`, or `cancelled` from either `queuing` (before recording starts) or `in_progress` (aborting a run already underway). A Coach starts the Session to move it from `queuing` to `in_progress`, allowed only once every present player has given Consent, and that transition is also when recording begins for every consented player. The per-participant recording lifecycle is a separate term (see **Session Participant status**). Each recording player delivers their own audio and video by calling `POST /sessions/{session}/recording` directly, independent of the coach; re-uploading replaces what that player already sent rather than adding to it (see `docs/adr/0012-per-player-recording-uploads.md`). A Coach completes an `in_progress` Session with a `game_events` payload once at least one recording player has stored both an audio and a video recording; the completion call itself carries no files. There is no separate stop declaration, and data never arrives ahead of it. Aborting an `in_progress` Session discards any recordings already delivered for it: recordings only survive a Session once it reaches `processing`, so cancelling mid-run — even after some players have uploaded — leaves nothing behind.
`processing` is the first state of the analysis pipeline: `processing`, then `timeline_ready`, then `analysis_ready`. A Session leaves `processing` for `timeline_ready` automatically once every Transcript has reached a terminal state, its Communication Events have been detected, Dead-Air Detection has run, and, if the Session was completed with Game Events, Game-State Alignment has run. A permanently `failed` Transcript still counts as done. A `processing` Session is not "live": it does not block the team from starting the next Session, `GET /sessions/{id}` refuses it with 409, and the team session index still lists it with a transcription-progress figure.

`timeline_ready` means "processed, under coach review": `GET /sessions/{id}` and the captions endpoint serve any member again, but the `session_timeline` and `timeline_summary` endpoints are coach-only. A Coach reviews every Timestamp, then marks the Session **Analysis Ready** (`analysis_ready`), which opens the timeline endpoints to players and lets them reply to Annotations. A Coach can reopen review at any time, dropping the Session back to `timeline_ready`. Every coach-driven transition that carries no payload (cancel, re-analyze, analysis-ready, reopen-review) goes through one endpoint, `POST /sessions/{id}/transitions` with a target status; `start` and `complete` are their own endpoints. See `docs/adr/0004-transcription-pipeline.md`, `docs/adr/0006-communication-events.md` and `docs/adr/0010-timeline-management.md`.
_Avoid_: lobby, waiting (used by the design flowchart, but `queuing` is the canonical term going forward); completed (the pipeline replaced it as the post-recording state); annotating, ready_for_review (superseded names for `analysis_ready`)

**Team Member**:
A user's ongoing relationship to a team (active, pending, or removed), independent of any particular Session.

**Online** (of a User):
Reflects whether a user is authenticated since their last logout — set the moment they log in or register (which also issues a token), cleared the moment they log out. Not a live connection/presence signal: a user who closes the app without logging out stays Online until they explicitly log out again. Broadcast to a user's active team only; a teamless user has no one authorized to see it.
_Avoid_: presence, active (as in "actively connected") — neither implies the auth-boundary meaning this term actually has

**Session Participant**:
A Team Member's participation in one specific Session. Its role is a snapshot of the member's team role at join time — it does not itself grant or change any authority. Where the participant sits in the recording lifecycle is tracked separately as **Session Participant status**.

**Session Participant status**:
Where one Session Participant sits in the recording lifecycle for their Session: `needs_consent`, then `ready`, then `recording`, then `completed`. It only ever moves forward, one step at a time. A player joins at `needs_consent` and reaches `ready` by giving Consent. A Coach joins at `ready` and never records, so a Coach's status stays `ready` for the whole Session. Every consented player moves to `recording` when the Coach starts the Session. Session completion then moves every `recording` participant to `completed` in one sweep, whether or not that participant's own AOD and VOD were among those delivered.
_Avoid_: state, stage

**Consent** (of a Session Participant):
A player's explicit agreement to be recorded in one Session. Giving it moves their Session Participant status from `needs_consent` to `ready`. It is asked once per Session and every time; a player who leaves and rejoins gives it again. A Coach has nothing to consent to. There is no Coach override to start a Session past a player who has not consented; that player leaves, or the Coach cancels the Session.
_Avoid_: opt-in, waiver, agreement

**Main Coach**:
A team's sole designated leader. Only the Main Coach can manage team membership (approve/reject join requests, remove members). If the Main Coach leaves the team, the team disbands.

**Assistant Coach**:
A Coach-role team member without leadership authority over team membership, but with equal standing to the Main Coach for running Sessions (any active Coach, main or assistant, may create a Session).

**Team Settings**:
A team's live, single configuration for detection tuning (dead-air threshold, keyword list, Communication Event Padding, Game Alignment Window). Editing it never retroactively changes analysis already produced for a Session that has moved past `processing`.

**Team Keyword**:
One configured single word a team wants detected in transcripts, tagged with a Category. Callouts read naturally as phrases (see the Category examples below), but detection in this prototype matches individual transcript tokens, so a Team Keyword is always one word — no spaces. Multi-word phrase matching is deferred (see `docs/adr/0001-single-word-team-keywords.md`).
_Avoid_: phrase

**Category** (of a Team Keyword / Callout Detection):
Fixed taxonomy of exactly two values, grounded in the communication-effectiveness research this project builds on:
- **Informative** — shares a current game-state fact the speaker knows that teammates may not (e.g. "enemy is planting", "2 enemies heard in main").
- **Declarative** — states the initiative the speaker is or will be taking (e.g. "popping a flash", "smoking heaven and CT").

A **Communication Event** carries a `communication_type` derived from the Categories of its Callout Detections: `informative` or `declarative` when they agree, `compound` when both are present. `compound` is a property of the event, not a value a Team Keyword can be tagged with — the keyword taxonomy stays two-valued.

**Dead Air**:
A stretch of a Session longer than the team's Dead-Air Threshold where no team member communicated, evaluated across the whole team's combined audio, not per player. `DetectDeadAir` computes it once per Session from the merged Communication Event spans and the session window, and persists a **Dead-Air Period** row for every such stretch, the gaps before the first callout and after the last one included. A period that has an uncalled Game Event inside it also gets a `dead_air` system Annotation naming those events; a period with nothing inside it is a row with no Annotation. Distinct from Game-State Alignment: Dead Air is the absence of communication, Alignment is whether present communication matched the game state. `timeline_summary` still also carries the older `talk_percentage` / `silence_percentage` / `longest_silence_percentage` proxy; retiring it is a named follow-up. See `docs/adr/0009-dead-air-detection.md`.
_Avoid_: silence (the proxy metric, not this entity)

**Dead-Air Period** (a kind of timeline entry):
One persisted stretch of team-wide Dead Air: a `dead_air_periods` row with a `start_ms`, an `end_ms`, and the Dead-Air Threshold it was found under, snapshot on the row. Session-level, never per player, and derived, so `reanalyze` deletes and recomputes it. Carries a `dead_air` Annotation only when a Game Event went uncalled inside it (strictly inside, `start_ms < match_time_ms < end_ms`). On `session_timeline` it joins the top-level `game_events` list as a `type: "dead_air"` entry with a real `duration_ms` and its `annotations`, merged with the point-in-time Game Events and ordered by `start_ms`.
_Avoid_: dead zone; gap

**Transcript** (of an AOD):
One AssemblyAI transcription of one player's stored audio, one per `aod_records` row. Carries its own status (`queued`, then `processing`, then `completed` or `failed`), the full text, the detected language, an overall confidence, and the raw provider response. Only English and Filipino are accepted; a transcript that comes back in any other language is `failed`. The sentence and word breakdown are separate terms (see **Transcript Sentence**, **Transcript Word**). Re-running a `failed` transcript overwrites it; transcripts are not versioned. See `docs/adr/0004-transcription-pipeline.md`.
_Avoid_: transcription (that is the process); subtitle. Caption is the frontend display feature built on Transcript Sentences, not a synonym for this record.

**Transcript Sentence** (of a Transcript):
One sentence of a Transcript as segmented by AssemblyAI's sentences endpoint, in transcript order, with its millisecond span, an aggregate confidence, and its Transcript Words. The unit the frontend caption display is built on. See `docs/adr/0005-sentence-indexed-transcript-storage.md`.
_Avoid_: caption (the frontend feature, not this record); line

**Transcript Word**:
One token of a Transcript, with its start and end in milliseconds and the model's confidence, kept in transcript order and grouped under its Transcript Sentence. Start and end are relative to the start of the audio file; under the current zero-offset assumption they are read as Timeline-relative.
_Avoid_: token

**Timestamp** (of a Timeline):
One marked interval on a Session's Timeline, with a start and end offset in milliseconds. Three kinds: a **Communication Event** (an interval), a **Game Event** (a point, `start == end`), and a **Dead-Air Period** (an interval). Each carries **Reviewed** state and a `created_by` (null for a detected row, a Coach for a **Manual Timestamp**). The `session_timeline` endpoint groups Communication Events under each participant (`data.participants[].timestamps[]`) and merges Game Events and Dead-Air Periods into one session-level `data.game_events[]` ordered by `start_ms`, a Game Event before a Dead-Air Period on a tie. During `timeline_ready` a Coach may add, edit and delete any kind through the timeline-management routes (see `docs/adr/0010-timeline-management.md`).
_Avoid_: marker; bare "event" (ambiguous between the kinds)

**Reviewed** (of a Timestamp):
A Coach's sign-off on one Timestamp during `timeline_ready`, recorded as `reviewed_at` and `reviewed_by`. Cleared automatically when the Timestamp's own fields are edited (not when an Annotation on it changes). A Manual Timestamp starts Reviewed, stamped to its creator. Every Timestamp must be Reviewed before the Session can be marked Analysis Ready; `timeline_summary` reports the running `reviewed / total` to a Coach while under review.
_Avoid_: checked, approved, signed

**Analysis Ready**:
The Session status (`analysis_ready`) after `timeline_ready`, entered by a Coach once every Timestamp is Reviewed. The point the `session_timeline` and `timeline_summary` endpoints open to players, and players may reply to Annotations. A Coach can reopen review, returning the Session to `timeline_ready` with existing Reviewed stamps kept. `app_sessions.analysis_ready_at` records when the Session last reached this status, stamped by `markAnalysisReady()` and left in place by reopen-review; it is what the Team Dashboard orders its Session Pool by. See **Session status**.

**Manual Timestamp**:
A Timestamp a Coach authored by hand during review, rather than one a detection job produced: `created_by` is set and the detection-snapshot column (`padding_ms` or `dead_air_threshold_ms`) is null. A Coach may create a Communication Event (on one participant, `communication_type` chosen freely) or a Dead-Air Period this way; Game Events are feed-only. A re-analysis discards every Manual Timestamp.
_Avoid_: custom timestamp, coach marker

**Communication Event** (a kind of Timestamp):
A cluster of one or more Team Keyword hits in a single player's Transcript that fall within Communication Event Padding of one another, taken as one callout. Typed `informative`, `declarative`, or `compound` by the Categories of its hits (see **Category**), and flagged redundant when the cluster repeats a Category or keyword with no new information. Each underlying hit is kept as a Callout Detection. Per player; never merged across players. See `docs/adr/0006-communication-events.md`.
_Avoid_: callout (reserved for the natural-language phrase a keyword approximates)

**Callout Detection** (of a Communication Event):
One Team Keyword hit inside a Communication Event: the matched token, its normalized form, the snapshot Category, the millisecond span, and the model confidence. The keyword text and Category are snapshot at detection time; a later Team Settings edit does not change a Callout Detection already stored.

**Communication Event Padding** (of Team Settings):
The team-configured gap, in milliseconds, within which consecutive Team Keyword hits are taken as one Communication Event — also the window for flagging redundancy. Stored as the `comm_event_padding` Team Settings row, default 2000. Snapshot when detection runs; a later edit does not re-run detection on an already-processed Session (use the re-analyze endpoint for that).

**Game Event** (a kind of Timestamp):
One occurrence in the played game — `kill`, `death`, `spike_plant`, `spike_defuse`, `round_win`, `round_lost` — placed on the Timeline by `match_time_ms`. For non-round types a `side` (`ally` or `enemy`) names the team the event favours, which drives its favourable / unfavourable / neutral valence. Until Riot API access exists, Game Events are supplied as a hand-authored `game_events` JSON payload on the Session completion call (`source: manual`); a `riot` source is reserved. Required on the completion call for this prototype: the call is refused with 422 if the payload is absent or empty. See `docs/adr/0007-manual-game-event-ingest.md` and `docs/agents/game-event-ingest.md`.
_Avoid_: match event; Riot event (the source, not the record)

**Match Time**:
A Game Event's position in milliseconds from the start of the played match. Under the zero-offset assumption (see `docs/adr/0004-transcription-pipeline.md`) match start equals Session start, so `match_time_ms` is read as Timeline-relative with no mapping.

**Annotation**:
A note attached polymorphically to a Timestamp. System-generated Annotations have a null `user_id` and a `topic` of `game_state_alignment` (on a Communication Event) or `dead_air` (on a Dead-Air Period), and the system never attaches one to a Game Event. A **Note** (`topic = note`, `user_id` the author) is free text a human adds to any Timestamp, Game Events included: a Coach while the Session is `timeline_ready` or Analysis Ready, a player only once it is Analysis Ready. Any Annotation, system or human, can carry one level of **Reply** rows (`topic = reply`) through a `parent_id`, gated the same way. A system Annotation is immutable; a Note or Reply is edited or deleted by its author only. See `docs/adr/0008-game-state-alignment.md`, `docs/adr/0009-dead-air-detection.md` and `docs/adr/0010-timeline-management.md`.

**Reply** (of an Annotation):
An Annotation with `parent_id` set, one level deep (a Reply's parent is always a top-level Annotation). Always human-authored. A Coach may reply while a Session is under review or Analysis Ready; a player only once it is Analysis Ready. Replying to a system Annotation is allowed. Deleting the parent takes its Replies with it.
_Avoid_: comment, thread

**Game-State Alignment** (of a Communication Event):
A system Annotation on a Communication Event, written when its keyword maps to a Game Event kind or a Game Event falls in the Game Alignment Window (or both). `assessment` is a soft, hedged read of the moment's valence, not a correctness verdict, using the Game Event valence rule from `docs/adr/0007-manual-game-event-ingest.md`: `possibly_positive` / `possibly_negative` / `neutral`. For a mapped keyword the sign comes from the nearest corroborating Game Event (a contradiction-pair Game Event forces `possibly_negative`; nothing corroborating or contradicting in the window is `neutral`). For a callout that maps nothing, the sign comes from the nearest in-window Game Event's valence, so a bad play near a no-claim callout still reads `possibly_negative`. Not a judgment of whether the communication was good, which stays with the coach; every `possibly_negative` body (and every dead-air body) closes with a "consider reviewing this moment" nudge that points at the clip rather than pronouncing on it.
_Avoid_: accuracy / correspondence (the values are valence, not a right/wrong call); reception

**Game Alignment Window** (of Team Settings):
The team-configured symmetric window, in milliseconds, around a Communication Event within which Game Events are considered for Game-State Alignment. API field `game_alignment_window_ms` (DB `setting_name` `game_alignment_window`, matching the `comm_event_padding` precedent), default 5000 — wider than Communication Event Padding because game-event timing is coarser. Snapshot onto each Annotation when alignment runs; a later edit does not re-run alignment on an already-processed Session.

**Team Dashboard**:
The read-only aggregate surface over a team's recent Sessions, two endpoints, both on the caller's active team (`?team={id}` to override), both open to any active member. `GET /api/dashboard/header` carries a team identity block, a Communication KPI card, and a communication-mix count card; every member gets the same team-wide numbers, only `identity.user` is the caller. `GET /api/dashboard/players` shapes its body by role: a Coach sees one line per active non-coach member, a player sees only their own line plus the team median of each metric. Computes nothing new about a Session, only pools what detection already produced. See `docs/adr/0011-team-dashboard.md`.
_Avoid_: report, analytics, stats page; Coach Dashboard (the header is not coach-only)

**Session Pool** (of the Team Dashboard):
The N most recent Analysis Ready Sessions for the team, ordered by `analysis_ready_at` descending, that every Dashboard number is computed over. N is the `?sessions=` query parameter, an integer 1 to 50, default 3. When the team has fewer than N Analysis Ready Sessions the data cards collapse to an "Insufficient sessions queried" message and only the identity and window blocks render.
_Avoid_: window (that is the from/to date span derived from the pool, not the pool itself)

**Communication KPI**:
The `kpi` card on the Dashboard header: `comm_frequency` (pooled Communication Events per minute over the summed Session window), `alignment_rate`, `absence_ms` (summed Dead-Air Period duration), and `calls_classified` (total Communication Events), all over the Session Pool.

**Alignment Rate** (of the Team Dashboard):
`(assessed - possibly_negative) / assessed` as a percent over the Game-State Alignment Annotations in scope, two decimals, null when nothing was assessed. A valence-derived proxy, not a correctness measure: it inherits the Game-State Alignment caveat, so it is never named `accuracy`. Only a `possibly_negative` reading lowers it. Reported on the Communication KPI card, on each Dashboard player line, and as a team median.
_Avoid_: accuracy, alignment accuracy, correctness
