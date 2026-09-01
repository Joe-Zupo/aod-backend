# 3. Session recording storage

`POST /sessions/{session}/complete` is the one path that turns delivered
AOD/VOD files into stored records and moves a Session to `completed` (see the
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

The completion call carries an audio and a video slot for every `recording`
player in the Session, and the submitted set of players must match that roster
exactly. Slots may be empty. The Session completes, and every `recording`
participant is swept to `completed`, once at least one slot holds both an audio
and a video. Records are written only by that call, in one transaction, so a
Session that is not `completed` never has recordings attached, and a player who
supplied nothing gets no record row at all.

## Considered options

- **One polymorphic `session_recordings` table with a `kind` enum.** Rejected.
  AOD and VOD diverge sharply downstream (transcription versus timeline video)
  and the shared column set is small, so two plain tables cost little and read
  clearer.
- **S3 from the start.** Rejected. No credentials and no deployment target are
  settled. The `disk` column on each row keeps the switch to a config change
  plus a file copy.
- **Accumulate uploads across several calls, or let the Coach omit players from
  the completion call.** Rejected. Multi-call accumulation would leave partial
  recordings attached to a Session that has not completed. A subset submission
  would complete a Session while silently dropping half the roster's uploads, so
  the call must name every `recording` player even when their slots are empty.
- **Require every player to have a full pair.** Rejected. Only one real pair is
  needed to prove the framework's premise, and a dead mic or a mid-run
  disconnect must not block completion for the rest of the roster.

## Consequences

- Uploads past roughly 40 MB need PHP `upload_max_filesize` / `post_max_size`
  and the web server body-size limit raised. That is infrastructure, not code.
  A completion call carries every player's video at once, so the ceiling has to
  fit the whole roster's uploads, not one player's.
- Adding more recordings after completion, or replacing one, needs its own
  endpoint. This one is single-shot. The first success moves the Session to
  `completed`, and every later call is a wrong-status rejection.
