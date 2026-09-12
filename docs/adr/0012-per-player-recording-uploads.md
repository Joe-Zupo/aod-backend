# 12. Per-player recording uploads

> Supersedes ADR 0003's single-shot, coach-carries-every-file completion
> call. Amends ADR 0004's description of `Session::complete()`.

`POST /sessions/{session}/complete` used to carry an audio and video slot for
every recording player in one multipart body (ADR 0003). That cannot work in
practice: each player records in their own browser on their own machine, and
routing every player's file through the coach's browser hits transport limits
long before the documented ceilings (five players at 200 MB audio + 2 GB video
each is well past any reverse-proxy body limit).

`aod_records` and `vod_records` were always keyed to `session_participant_id`,
one row per player per session — only the route disagreed. This ADR moves
delivery to match the data model.

## Decision

A new endpoint, `POST /sessions/{session}/recording`, lets each participant
upload their own audio and/or video directly, independent of the coach's
completion call. Eligibility is the participant's own state: `participant_status
=== recording` and `left_at IS NULL` — the same per-participant granularity
`consent` already uses, not a session-wide status check. Re-uploading replaces
the participant's existing `AodRecord`/`VodRecord` row in place (both tables
already carry a unique `session_participant_id` constraint) rather than
creating a second one; the old file is deleted before the new one is stored,
since the extension can differ between attempts.

Each upload optionally carries `audio_client_started_at` / `video_client_started_at`
— when that browser actually began recording. Stored and otherwise unused for
now (see ADR 0003's own note on the zero-offset assumption and ADR 0004's
"Zero-offset assumption" section); this is one nullable column now against a
second migration later if the dead-air alignment window ever needs correcting
for cross-client start skew.

`POST /sessions/{session}/complete` no longer carries any files or a `players`
roster. Its only remaining input is `game_events`. The "at least one
participant has both audio and video" gate, and the transcript creation +
`SubmitTranscription` dispatch, are both re-derived from whichever
`AodRecord`/`VodRecord` rows already exist for `recording` participants at the
moment `complete()` runs, rather than from request input — the DB's own
`recording`-status participants are already the authoritative roster, so there
is nothing external left to reconcile a submitted list against.

`Session::cancel()` gains a consequence it never needed under ADR 0003:
because uploads can now land during `in_progress` independently of
completion, aborting a session must explicitly discard any `AodRecord`/
`VodRecord` already stored for it (and their files), restoring the original
"cancelling discards everything" invariant that a single completion call used
to give for free.

## Considered options

- **Keep `/complete` as the single upload path, chunked over several calls.**
  Rejected. Accumulating partial uploads across several calls to one coach-only
  endpoint still requires the coach's browser to relay every player's file; it
  fixes nothing about the transport problem, only re-chunks it.
- **A participant-id path parameter (`POST /sessions/{session}/participants/{participant}/recording`).**
  Rejected. Every other self-service action in this API (`consent`, `join`)
  resolves "my own participant row" from the authenticated user, not a URL
  parameter; a participant id would only ever be legally the caller's own, so
  naming it explicitly adds a spoofing surface (must-check-ownership) for no
  benefit.
- **One shared `client_started_at` per upload call instead of one per file.**
  Rejected. Audio and video can be captured by separate `MediaRecorder`
  instances client-side and can arrive as separate calls (this endpoint allows
  either file independently), so a single shared value would be wrong
  whenever only one file is present in a given call.

## Consequences

- A late-arriving upload after the coach has already called `/complete` (which
  sweeps `recording` participants to `completed`) is rejected — the
  eligibility gate is `participant_status === recording`, and completion moves
  past that status. This is intentional: once a session has moved to
  `processing`, there is no coherent point in its lifecycle for a fresh
  recording to land.
- A participant who leaves mid-recording (`left_at` set, `participant_status`
  unchanged — see `SessionParticipant::leave()`) can no longer upload, even
  though their `participant_status` alone would still read `recording`.
- `CompleteSessionRequest` and `SessionController::complete()` both shrink
  substantially; the removed `assertRosterMatch()` guard (ADR 0003) has no
  replacement, because there is no longer an external roster to validate
  against the DB's own state.
