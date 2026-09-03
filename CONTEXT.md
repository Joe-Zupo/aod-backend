# AOD Backend

API backend for the Audio-on-Demand (AOD) Communication Analysis framework: esports teams record per-player audio and gameplay video during scrims, transcribe and analyze communication, and cross-reference it against game events for coach-led post-game review.

## Language

**Session**:
A single recorded game (one scrim or match), from lobby through completion. Scope is deliberately narrow for this prototype: one Session always covers exactly one game, never a multi-game block (e.g. a Bo3 or a night of several scrims). Every Session carries a `session_code`, `SESSION_` plus its id zero-padded to at least three digits (`SESSION_048`), assigned on creation and used as its human-facing reference; `session_name` is the free-text title.
_Avoid_: scrim, match, game (when referring to the system entity)

**Timeline**:
The synchronized data spine of a Session, the canonical clock that AOD, VOD, and Riot match data are aligned to via millisecond offsets. Created when the Session enters `processing`, not at Session creation, so a `queuing`, `in_progress`, or `cancelled` Session has none (see `docs/adr/0004-transcription-pipeline.md`). Its Timestamps — Communication Events, and Game Events when supplied — are produced during `processing`; the Session moves to `timeline_ready` once they are complete and any Game-State Alignment has run (see `docs/adr/0006-communication-events.md`, `docs/adr/0008-game-state-alignment.md`).

**Session status**:
A Session's lifecycle state: `queuing`, then `in_progress`, then `processing`, or `cancelled` from either `queuing` (before recording starts) or `in_progress` (aborting a run already underway). A Coach starts the Session to move it from `queuing` to `in_progress`, allowed only once every present player has given Consent, and that transition is also when recording begins for every consented player. The per-participant recording lifecycle is a separate term (see **Session Participant status**). A Coach completes an `in_progress` Session in one call that carries an audio and video slot for every recording player; it moves to `processing` once at least one slot holds both. There is no separate stop declaration, and data never arrives ahead of it. Aborting an `in_progress` Session never persists partial AOD/VOD: recordings only become server-side records once a Session reaches `processing`, so cancelling mid-run discards nothing that was ever saved.
`processing` is the first state of the analysis pipeline, whose full target is `processing`, then `timeline_ready`, then `annotating`, then `ready_for_review` (shown to coaches as "Timeline Ready"). `processing` and `timeline_ready` are built (see `docs/adr/0004-transcription-pipeline.md` and `docs/adr/0006-communication-events.md`); `annotating` and `ready_for_review` arrive with the milestones that produce their transitions. A Session leaves `processing` for `timeline_ready` automatically once every Transcript has reached a terminal state, its Communication Events have been detected, and — if the Session was completed with Game Events — Game-State Alignment has run. A permanently `failed` Transcript still counts as done. A `processing` Session is not "live": it does not block the team from starting the next Session, `GET /sessions/{id}` refuses it with 409, and the team session index still lists it with a transcription-progress figure. `timeline_ready` opens the read surface: `GET /sessions/{id}` succeeds again, and the `session_timeline`, `timeline_summary`, and captions endpoints serve the Session.
_Avoid_: lobby, waiting (used by the design flowchart, but `queuing` is the canonical term going forward); completed (the pipeline replaced it as the post-recording state)

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
A stretch of a Session where no team member communicated at all, evaluated across the whole team's combined audio — not per individual player. Not yet detected as such: `timeline_summary` reports a provisional team-aggregate silence proxy (`total_silence_ms`, `longest_silence_ms`) derived from Communication Event spans, pending the real Dead Air milestone (threshold, Riot cross-reference, system annotation). Distinct from Game-State Alignment: Dead Air is about the absence of communication, Alignment about whether present communication matched the game state.

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
One marked interval on a Session's Timeline, with a start and end offset in milliseconds. Two kinds are built: a **Communication Event** (an interval) and a **Game Event** (a point, `start == end`). Coach-authored review points may become a third. The `session_timeline` endpoint groups Communication Events under each participant (`data.participants[].timestamps[]`) and returns Game Events as one session-level `data.game_events[]` ordered by match time.
_Avoid_: marker; bare "event" (ambiguous between the two kinds)

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
A note attached to a Timestamp, primarily a Communication Event. System-generated Annotations have a null author and a `topic` (the only one so far is `game_state_alignment`, see **Game-State Alignment**); coach- and player-authored free-text Annotations extend the same entity later. See `docs/adr/0008-game-state-alignment.md`.

**Game-State Alignment** (of a Communication Event):
A system Annotation on a Communication Event whose keyword makes a checkable game-state claim, recording whether nearby Game Events matched it: `accurate` (a Game Event of the mapped kind fell in the Game Alignment Window), `inaccurate` (a contradiction-pair Game Event fell in the window), or `neutral` (neither). Descriptive correspondence only — not a judgment of whether the communication was good, which stays with the coach. A callout that makes no game-state claim is not assessed and carries no Annotation.
_Avoid_: accuracy (as the label — the values include `inaccurate` and `neutral`); reception

**Game Alignment Window** (of Team Settings):
The team-configured symmetric window, in milliseconds, around a Communication Event within which Game Events are considered for Game-State Alignment. Stored as `game_alignment_window_ms`, default 5000 — wider than Communication Event Padding because game-event timing is coarser. Snapshot when alignment runs; a later edit does not re-run alignment on an already-processed Session.
