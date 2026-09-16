# 13. Recording control and departure

> Adds `POST /sessions/{session}/start-recording`, `/stop-recording` and
> `/leave` for participants, and `to=queuing` on the transitions endpoint for
> Coaches. Establishes one rule: leaving `participant_status = recording`
> discards that participant's stored recordings. Opens `SessionPolicy::join()`
> to any active member of a non-terminal session. Amends ADR 0012. No schema
> change.

ADR 0012 made `participant_status = recording` carry three meanings at once. It
is the upload gate (`uploadRecording()` requires it, plus `left_at IS NULL`), it
is the transcription set (`complete()` creates a `Transcript` for every stored
AOD belonging to a `recording` participant), and it is the sweep set (those same
rows move to `completed`).

Nothing could move a participant off that status mid-run. A player whose
microphone failed had no way out except leaving, and `SessionParticipant::leave()`
was unreachable over HTTP. The Coach's only halt was `cancel()`, which ends the
session outright.

## Decision

### One rule: leaving `recording` discards your recordings

Four paths now move a participant off `recording`, and all four destroy that
participant's `AodRecord` and `VodRecord` rows and the files behind them:

- a player's own `stop-recording`
- a player's own `leave`
- the regress, for everyone still active
- `cancel()`, which already did this session-wide

The alternative was letting stored files outlive the status that authorised
them. That produces a specific bug: a player uploads, then stops or leaves, and
`complete()` skips their row because it is no longer `recording`. Their audio
stays in storage, never transcribed, never swept, with nothing in any response
saying so. Discarding is the only outcome that keeps "what gets transcribed"
and "who is recording" the same question.

`Session::discardRecordingsFor()` is the seam. It deletes the rows and
accumulates their storage paths on the session instance; the entry point calls
`flushDiscardedRecordings()` once its transaction has committed. Rows inside the
transaction and files after it, because a rollback that had already unlinked
files would leave rows pointing at nothing. The rules nest — leaving triggers a
regress, which discards again — so one accumulator and one flush beats each rule
unlinking on its own. `cancel()` was rewritten onto the same seam rather than
keeping its own copy.

### The player endpoints

`start-recording` moves `ready -> recording`, a forward step `advanceStatusTo()`
already allows. From `needs_consent` it is a 422 telling the caller to consent
first, never a silent 200: a client told it is recording will upload files the
server then refuses.

`stop-recording` moves `recording -> needs_consent` and discards. Returning to
the run means consenting again, then starting again. `recordConsent()` already
permitted this — its guard is any non-terminal status plus `needs_consent` or
`ready` — so it needed no change.

`leave` stamps `left_at`, resets the row to its role's initial status, and
discards. The reset is what makes departure total: a departed row left at
`recording` would still have its files transcribed after the player walked away.

Both responses carry `discarded: {audio: bool, video: bool}`. Stopping and
leaving destroy work and return 200, and without this a client cannot tell "you
stopped, nothing lost" from "you stopped, your take is gone".

### One regress trigger

`regressIfNobodyRecording()`: an `in_progress` session with no active
participant at `recording` returns to `queuing`, resets everyone active to their
role's initial status, and discards. A run nobody is recording is not a run,
whether the players stopped or left. `leave()` still runs
`cancelIfNoParticipantsRemain()` first, so cancel-on-empty wins over regress.

### The Coach's stop

`POST /sessions/{session}/transitions` with `to=queuing`, coach-only,
`in_progress` only, naming its target status the way `cancelled` and
`analysis_ready` do. It ends the run, keeps the lobby, and destroys every
uploaded file in the session. What separates it from `cancel()` is that the
roster survives and the session can be started again.

### Anyone may join a session under way

`SessionPolicy::join()` allows any active team member at `queuing` or
`in_progress`. ADR 0012's per-participant upload gate is what makes this safe: a
latecomer arrives at `needs_consent` and can deliver nothing until they consent
and start recording, so they are harmless by construction rather than by roster
bookkeeping.

## Consequences

- **A team that all stops before the Coach completes destroys the scrim.** Each
  stop discards that player's files; the last one triggers the regress, which
  discards the rest. The Coach's completion then fails with "Only an in_progress
  session can be completed" and every file is gone. This was taken deliberately:
  completion needs at least one stored AOD/VOD pair from a `recording` player, so
  a run with nobody recording has nothing left to complete. The client must make
  the ordering loud — the Coach completes, then players stop.
- `stop-recording` and `leave` are destructive and return 200. A double-tapped
  button destroys a take. The `discarded` block is the only signal.
- The Coach can destroy a scrim through an endpoint with the word "stop" in it,
  without the word cancel appearing anywhere.
- Restarting after any regress costs a full re-consent round from every player,
  because consent is per-run (ADR 0002).
- ADR 0012's consequence about a departed participant keeping
  `participant_status = recording` no longer holds, and is amended there.
