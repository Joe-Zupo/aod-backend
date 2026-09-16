# 14. Participant status is a participation lifecycle

> Redefines `participant_status` from "where this participant is in the
> recording lifecycle" to "where this participant is in the session". Replaces
> the flat status sequence with an adjacency map. Makes `complete()` sweep every
> active participant rather than only the recording set. Adds a one-shot
> backfill. No schema change.

A coach on a session that finished three weeks ago read `participant_status:
"ready"`. Nothing was ready. The same was true of a player who stopped early: a
finished session left them at `needs_consent`, as though they were about to
consent to a run that was already over.

That followed from what the field meant. `completed` sat at the end of one line
— `needs_consent → ready → recording → completed` — and a coach never enters
that line, because a coach never records. There was no terminal state for
anyone who did not record, so they stayed wherever the run left them, forever.

## Decision

### The field describes participation, not recording

`completed` means "this session is over for me". A coach reaches it without ever
recording. So does a player who stopped early, or one who joined late and never
started.

The alternative was keeping the recording reading and fixing the display in the
client, by having it show a session-level status instead of a participant one.
Rejected: it leaves the database holding a value that is false on its face, and
every consumer has to learn the same workaround.

### The machine is a graph, not a line

`PARTICIPANT_STATUS_SEQUENCE` was a flat list and `advanceStatusTo()` walked it
by index arithmetic, so "allowed" meant `$currentIndex + 1`. That shape cannot
express more than one edge into a terminal state.

`PARTICIPANT_STATUS_TRANSITIONS` replaces it with the moves each status allows:

```
needs_consent → ready, completed
ready         → recording, completed
recording     → completed
completed     → (nothing)
```

`advanceStatusTo()` checks membership. Same guard, same exception, same silent
no-op on a move to the current status.

The sequence was already a lie about this state machine — `resetStatus()` exists
because stopping, leaving and regressing all move a row backwards, which a
strictly forward line cannot describe. A map says what is actually allowed, and
the next edge becomes a data change rather than another bypass.

Reaching `completed` from `needs_consent` is not a consent loophole. It ends
participation rather than granting it, and `start()` still requires every active
player to be `ready`.

### Completion ends the session for everyone in it

`complete()` swept the `recording` set. It now sweeps every participant with
`left_at IS NULL`. One rule, applied to whoever was still there.

The same filter applies to the two questions completion asks before that: whether
anyone delivered a full pair, and whose audio to transcribe. All three read the
participants still in the session, so they cannot disagree about who the run
belonged to.

A departed participant is not swept. They were not in the session when it ended,
and `left_at` already records what happened.

### Cancellation is left alone

`cancel()` still stamps `left_at` and leaves `participant_status` untouched. It
departs everyone, so nothing reads those rows as active, and `completed` would
claim the run produced something it did not. The gap this ADR fixes exists
precisely because `complete()` departs nobody, which leaves `participant_status`
as the only field that can say the session is over.

The rejected alternative was a separate terminal status for cancellation. No
caller distinguishes the two cases, so it would be vocabulary without a reader.

### Sessions that already finished

`App\Support\CompleteStrandedParticipants` completes every active participant on
a session at `processing`, `timeline_ready` or `analysis_ready`. Cancelled
sessions are excluded for free, because `cancel()` departed their participants.

It is a class rather than inline migration code so the rule can be tested
against rows a test builds; a migration running under `RefreshDatabase` executes
before any fixture exists. The migration calls it and has no `down()` — the
earlier statuses are not recoverable, and they were describing a session that
had already ended.

## Consequences

- Completion now broadcasts `SessionParticipantStatusChanged` for every active
  participant instead of only the recorders. A five-player session with two
  coaches emits seven instead of five, alongside the `SessionStatusChanged` it
  already sent.
- `complete()` reads participants who are still in the session throughout: the
  pair guard, the transcript loop and the sweep all filter on `left_at`. A
  departed row's stored pair counts for nothing and its audio is not
  transcribed. Unreachable through the endpoints, because `leave()` discards
  recordings and resets the row (ADR 0013), but the queries now agree rather
  than depending on that.
- `CONTEXT.md` loses two sentences: that a coach's status stays `ready` for the
  whole session, and that the machine only ever moves forward one step at a
  time.
