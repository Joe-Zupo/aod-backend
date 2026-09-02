# 4. Transcription pipeline

Completing a session used to be its last state change: `POST
/sessions/{session}/complete` moved it straight to `completed` and stored the
per-player AOD/VOD (see ADR 0003). Completion is now the entry to an analysis
pipeline. The coach's completion call moves the session to `processing`, and the
first pipeline step, transcribing each stored AOD through AssemblyAI, runs on
the queue from there.

## Session states

The post-recording lifecycle target is `processing -> timeline_ready ->
annotating -> ready_for_review`, replacing the single `completed` state.
`ready_for_review` is the terminal, displayed to coaches as "Timeline Ready" and
beyond. This issue builds only `processing`: the session enters it on
completion and there is no coded transition out yet. The milestone that finishes
the pipeline (Timeline offset mapping, then annotation) adds the later states
alongside the transitions that produce them.

`processing` is not a "live" session. It is outside `NON_TERMINAL_STATUSES`, so
it does not block the team from starting the next scrim, and the auto-cancel and
consent windows are closed for it. Recording is done and stored; only analysis
is left.

`SessionParticipant` status is untouched. It still sweeps to `completed` on the
completion call, which is accurate: that enum tracks the recording lifecycle,
which is genuinely finished.

## Timeline is created lazily

The `Timeline` row is created in `Session::complete()`, as part of the move to
`processing`, not in `Session::createForTeam()`. A `queuing` or `in_progress`
session has no Timeline (`session->timeline` is null). This reverses the "a
session never exists without its timeline" decision from the session-lifecycle
spec. An empty Timeline hung off a lobby carried no information, and every
column the Timeline will grow (`actual_started_at`, `duration_ms`,
`riot_start_offset_ms`) is only knowable after recording.

## Transcript storage

Two new tables, standalone rather than folded onto `aod_records` as the original
ER diagram had them:

- `transcripts`, one row per AOD (`aod_record_id` unique). Holds the provider id,
  a `status` of `queued` / `processing` / `completed` / `failed`, the full
  `text`, `language_code`, an overall `confidence`, `audio_duration_ms`, an
  `error` string, the raw AssemblyAI JSON in `raw_response`, and a `poll_count`
  that bounds the poll loop.
- `transcript_words`, the ordered per-token breakdown (`position`, `word`,
  `start_ms`, `end_ms`, `confidence`).

The ER diagram is stale (its `aod_records` still has a `timeline_id` the shipped
schema does not) and its word rows live under `comm_events`, which do not exist
yet. A dedicated transcript row also keeps any future re-processing clean: reset
one row and drop its words, rather than nulling eight columns on `aod_records`.
When the
detection milestone builds `comm_events` / `comm_event_words`, it relates them to
`transcript_words` or copies the spans it needs; that milestone decides.

## Zero-offset assumption

AssemblyAI word timestamps are relative to the start of the audio file. This
milestone treats every AOD as starting at session start, so a word's `start_ms`
is read as Timeline-relative with no mapping. Real per-recording offset capture
(clock skew, upload lag, a player who joined after start) stays deferred exactly
as ADR 0003 left it, and is the Timeline milestone's problem. `transcripts`
carries no `sync_offset_ms` and the Timeline carries no offset columns yet.

## Trigger, jobs, and polling

`Session::complete()` creates one `queued` transcript row per stored AOD inside
its transaction, then, once that transaction has committed, dispatches one
`SubmitTranscription` job per row. Dispatching after commit keeps a worker from
picking up a row a rolled-back completion left behind.

`SubmitTranscription` uploads the file, submits it with the team's current Team
Keywords as a `word_boost` list, `language_detection` on, `speaker_labels` off
(each AOD is one player's mic), stores the returned provider id, moves the row to
`processing`, and dispatches `PollTranscription` with a delay.

`PollTranscription` re-dispatches itself with a delay while AssemblyAI is still
working, bounded by `services.assemblyai.max_polls` (`poll_count` on the row
survives the re-dispatch that a job attempt count would not). On `completed` it
writes the word rows and the transcript fields in one transaction. A webhook
would avoid polling but needs a public callback URL, which dev does not have;
`services.assemblyai.webhook_url` is reserved for when one exists.

Transcription is entirely automatic: it is a consequence of completing the
session, with no endpoint to trigger or re-trigger it. A transcript that reaches
`failed` stays `failed` for now. A recovery path (a scheduled retry of `failed`
rows, or a re-run endpoint) is a follow-up, once there is a real need for one.

## Failure model

- AssemblyAI returns `status: error` (bad or corrupt audio): the transcript is
  `failed` with the provider message, no retry, it would only fail again.
- The detected language is not `en` or `tl`: `failed`, "language not supported".
  A file that is heavily code-switched Taglish still resolves to one language;
  better code-switch handling is out of scope.
- The poll never resolves: `failed` with a timeout error once `poll_count`
  reaches `max_polls`.
- An HTTP or network error on upload / submit / poll: the job retries with
  backoff up to `services.assemblyai.job_tries`, then its `failed()` hook marks
  the transcript `failed`.
- A player who supplied no AOD has no `aod_records` row, so no transcript and
  nothing to do.

Each failure is scoped to one AOD. The others in the session are unaffected.

## Progress

`GET /sessions/{session}` is refused with 409 while the session is `processing`:
the pipeline is mid-run and there is no coherent single-session view yet. The
team session index (`GET /teams/{team}/sessions`) still lists a `processing`
session and carries a `transcription: { total, completed, failed }` aggregate on
its entry, derived from the transcript rows. There is no progress broadcast in
this issue; a `TranscriptionProgressChanged` event is a follow-up. A coarse
`Timeline.processing_state` for the "Processing -> Timeline Ready" headline is
left to the pipeline-completion milestone.

## Considered options

- **A re-run endpoint (`POST /sessions/{session}/transcribe`) alongside the
  auto-dispatch.** Dropped. Transcription follows from completion with nothing to
  call; a `failed` transcript has no recovery path yet, and adding one before it
  is needed is speculative surface.
- **A `Session` status per pipeline step vs. state on the transcript rows.**
  The session enum gains only `processing`. Per-transcript state lives on the
  transcript rows, so re-processing does not reopen a session state and consumers
  of session status do not have to understand transcription.
- **One orchestrator job per session that fans out internally.** Rejected in
  favour of one job per AOD: it is the natural retry unit, AssemblyAI's own
  concurrency queue absorbs the fan-out, and one player's failure does not touch
  the others.
- **A scheduled `transcripts:poll` sweep command instead of a self-rescheduling
  job.** Rejected. The self-rescheduling job needs no scheduler entry (dev
  already runs `queue:work`) and keeps all of one transcript's logic in one
  place.
- **Merged team transcript instead of per-player.** Rejected. Callouts are
  per-speaker and dead-air is computed later from the combined audio as its own
  thing; a merged transcript discards speaker identity we already have.

## Consequences

- Nothing calls AssemblyAI until `ASSEMBLYAI_API_KEY` is set. Tests fake the HTTP
  layer.
- Async transcription needs `php artisan queue:work` running during dev.
  `QUEUE_CONNECTION=database` and the `jobs` table are already in place.
- The `word_boost` list sent at submit time is the team's keywords as they stand
  then. A later edit to Team Settings does not reach an already-submitted
  transcript, which matches "editing settings never retroactively changes
  analysis" from `CONTEXT.md`.
- A session can sit in `processing` indefinitely until a later milestone adds the
  forward transition. That is expected for a prototype built milestone by
  milestone.
