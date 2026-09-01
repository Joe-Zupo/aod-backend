# 3. Session recording storage

`POST /sessions/{session}/complete` is the one path that turns a delivered
AOD/VOD pair into stored records and moves a Session to `completed` (see the
**Session status** entry in `CONTEXT.md`). The files, and the tables that track
them, had no design yet.

AOD and VOD are written to the `local` (private) disk under
`session-recordings/{session_id}/{user_id}/`, one `aod.{ext}` and one
`vod.{ext}` per participant. Each row stores its own `disk` name, so moving to
another disk later is a data migration, not a schema change.

They are tracked in two tables, `aod_records` and `vod_records`, each with a
unique `session_participant_id`, the storage `disk` and `path`, and the upload's
`original_filename`, `mime_type`, and `size_bytes`. There are no duration or
timeline-offset columns. Timeline mapping is a later milestone and will add what
it needs.

The completion gate is deliberately loose. One participant's AOD and VOD,
delivered in a single call, complete the Session and sweep every `recording`
participant to `completed`. Per-participant completeness is not checked. Records
are written only by the call that completes the Session, in one transaction, so
a Session that is not `completed` never has recordings attached.

## Considered options

- **One polymorphic `session_recordings` table with a `kind` enum.** Rejected.
  AOD and VOD diverge sharply downstream (transcription versus timeline video)
  and the shared column set is small, so two plain tables cost little and read
  clearer.
- **S3 from the start.** Rejected. No credentials and no deployment target are
  settled. The `disk` column on each row keeps the switch to a config change
  plus a file copy.
- **Accumulate uploads across several calls, or require every player to have a
  pair.** Rejected. The prototype's thesis needs one real pair to exist, not a
  complete set, and multi-call accumulation would mean partial recordings
  attached to a Session that has not completed.

## Consequences

- Uploads past roughly 40 MB need PHP `upload_max_filesize` / `post_max_size`
  and the web server body-size limit raised. That is infrastructure, not code.
- Adding more recordings after completion, or replacing one, needs its own
  endpoint. This one is single-shot. The first success moves the Session to
  `completed`, and every later call is a wrong-status rejection.
