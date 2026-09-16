# 15. End of run and end of participation

> Adds a session status, `delivering`, between `in_progress` and `processing`.
> Adds a participant status, `ending`, held while the session is `processing`.
> Puts each participant's delivery state on the participant resource and on
> every participant broadcast. Adds `started_at` to `app_sessions`. Amends ADR
> 0012 and ADR 0014.

A coach presses "match over" and five browsers have to stop their recorders and
upload. Before this ADR nothing on the server could tell them to. Every move out
of `in_progress` either locked uploads out or destroyed them. `complete()` swept
every recorder past `recording` in the same transaction that moved the session
to `processing`, so an upload still in flight was refused. `to=queuing` and
`cancel()` both discard every take.

The client could not fill the gap. A Pusher client event is fire and forget, so
a player reconnecting at the moment the coach pressed the button would never
hear it and would lose their recording without any response saying so. Raised
in `Joe-Zupo/aod-backend#24` while specifying `Maykiyel/aod-frontend#7`.

## Decision

### The end of a run is a status

`delivering` sits between `in_progress` and `processing`. A Coach reaches it
with `POST /sessions/{session}/transitions` and `to=delivering`, the same way
as the other payload-free moves, and it broadcasts `SessionStatusChanged`. Every
client finishes its recorder and uploads on that event. A client that missed
the broadcast sees `delivering` on its next `GET /sessions/{session}`.

The rejected alternative was a nullable timestamp on a session that stays
`in_progress`. It would have left every guard reading two fields to answer one
question, and the broadcast would have needed an event of its own.

`delivering` names the window in which players deliver, and it stays accurate
after the last file has arrived, because the session is still waiting on the
coach. `uploading` was rejected for that reason.

### What `delivering` allows

- Uploads stay legal, under the same guard: an active participant at
  `recording`.
- `complete()` is legal only from `delivering`. From `in_progress` it is refused
  with a message naming the missing step. The threshold is unchanged, at least
  one active player with a full pair, because the coach can now see who has
  delivered and decide.
- `to=queuing` and `to=cancelled` behave as they do from `in_progress`. Both
  discard every recording (ADR 0013).
- There is no move back to `in_progress`. Recorders have already stopped, and a
  second upload replaces the first, so resuming would silently cut the take in
  half. A coach who pressed too early restarts with `to=queuing` or completes
  with what arrived.
- A player cannot start or stop recording. Stopping would discard a take they
  have just delivered.
- A departure discards that player's recordings (ADR 0014), and the regress
  rule applies as it does in `in_progress`. With nobody left at `recording` no
  full pair can arrive, so the session returns to `queuing` rather than waiting
  for a completion that cannot succeed.

`delivering` is one of the `NON_TERMINAL_STATUSES`. Uploads are still arriving
and nothing has been handed to the pipeline, so the team has not finished this
scrim.

Players stay at `recording` throughout. Since ADR 0014 the field describes
participation, and a player delivering their take is still part of the run.

### Participation ends in two steps

`completed` used to arrive at `complete()`. A player whose audio was still being
transcribed read the same status as one whose timeline was ready, so a client
could not tell "my part is over" from "my part is done".

`ending` covers the gap. `complete()` sweeps every active participant to
`ending`. `AdvanceSessionAfterProcessing` sweeps them to `completed` in the
transaction that moves the session to `timeline_ready`. The rule is that an
active participant is `ending` exactly while their session is `processing`, so
`reanalyze()` resets active rows from `completed` back to `ending`, and the
fan-in completes them again.

The transition map becomes:

```
needs_consent → ready, ending
ready         → recording, ending
recording     → ending
ending        → completed
completed     → (nothing)
```

`completed` is reachable only through `ending`. The backward move made by
`reanalyze()` goes through `resetStatus()`, like the other resets.

Rows already in the database were corrected by
`App\Support\EndProcessingParticipants`. Active participants of a session at
`processing` move from `completed` to `ending`. Sessions at `timeline_ready` or
`analysis_ready` already hold the right value.

### Delivery state travels with the participant

`SessionParticipantResource` carries `aod` and `vod`, each a
`RecordingMetaResource` or `null`. `size_bytes` lets the client say "audio
delivered, 4 MB" rather than draw a tick. A Coach row has both `null`.

`uploadRecording()` broadcasts `SessionParticipantRecordingUploaded` once its
transaction has committed. A delivery announced before commit could show the
coach a file whose row rolled back, which would mislead the decision to
complete.

Every participant event (`Joined`, `Left`, `StatusChanged` and the new one)
broadcasts one payload shaped like the resource, delivery state included. A
client therefore never has to know which moves discard recordings: a `Left`
after a discard carries `aod: null` because that is the truth.

### When the run began

`app_sessions.started_at` is nullable. `start()` stamps it on every run and the
return to `queuing` clears it, so it always means "when the current run began".
`created_at` is when the coach made the session in the lobby, and `updated_at`
only happens to equal the start until the next write to the row.

## Consequences

- ADR 0012 holds, with one limit. Players still deliver independently of the
  coach, but delivery now happens during `in_progress` and `delivering`, and
  `complete()` no longer races an upload.
- ADR 0014's transition map is replaced by the one above. Its rule that
  completion ends the session for everyone still in it holds, and now arrives
  in two steps.
- A session now emits one participant broadcast per active participant twice
  after the run, at completion and at `timeline_ready`, and twice more for each
  re-analysis.
- `CONTEXT.md` loses the sentence saying there is no separate stop declaration.
