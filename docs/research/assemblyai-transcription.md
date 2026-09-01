# AssemblyAI pre-recorded transcription, research notes

Written 2026-09-01 for the transcription milestone. First primary source is the
AssemblyAI docs; secondary numbers are marked as such.

## One file per request

The transcript endpoint takes a single audio source, not a batch. To transcribe N
player recordings you make N submissions.

- Remote file, pass `audio_url` to `POST /v2/transcript`.
- Local file, `POST /v2/upload` first, get back an `upload_url`, then pass that as
  `audio_url` to `POST /v2/transcript`.
- Source, https://www.assemblyai.com/docs/pre-recorded-audio/getting-started/transcribe-an-audio-file.md

There is no documented endpoint that accepts multiple files in one call. Bulk work
is "submit many single-file jobs, let concurrency absorb them", per
https://www.assemblyai.com/docs/pre-recorded-audio/guides/bulk-transcription-and-load-tests-at-scale

## Limits

**Concurrency, not a per-request cap.** The limit is how many async jobs run at
once, not how many you may submit.

- Free accounts, 5 parallel async transcriptions.
- Paid accounts, 200 by default, raised for free on request. The scale blog calls
  it "unlimited concurrency on standard plans".
- Over the limit, submissions are accepted and queued server-side in FIFO order,
  drained automatically as running jobs finish. AssemblyAI already runs the queue.
- Sources, https://www.assemblyai.com/docs/pre-recorded-audio/concurrency and
  https://support.assemblyai.com/articles/1700416323-what-are-my-concurrency-limits

**HTTP rate limit, separate from concurrency.** 20,000 requests per 5 minutes,
counting POST submissions and GET polls together, 403 on exceed. Not a concern at
our scale.
Source, https://www.assemblyai.com/docs/pre-recorded-audio/concurrency

**File constraints.** 160 ms to 10 hours per file, up to 5 GB per request, most
common audio and video formats.
Source, https://www.assemblyai.com/docs/pre-recorded-audio/getting-started/transcribe-an-audio-file.md

## Turnaround

The job is async. After submit you either poll `GET /v2/transcript/{id}` until
`status` is `completed` or `error` (docs example polls every 3 s), or register a
webhook. Webhooks need a public callback URL, which dev does not have, so polling
is the near-term path.
Source, https://www.assemblyai.com/docs/pre-recorded-audio/getting-started/transcribe-an-audio-file.md

Processing itself is fast once a job starts. Artificial Analysis measures roughly
95x real time for Universal-3 Pro and 121x for Universal (seconds of audio per
second of processing), so a 60-minute recording finishes in about 30 to 60 s of
wall time. Treat that as indicative, not contractual. End to end is longer once
upload, queue wait, and polling interval are added, plan for 1 to 3 minutes per
recording in practice. AssemblyAI's own guidance is to optimise for aggregate
throughput, not per-file latency, and to chunk very long files.
Sources, https://artificialanalysis.ai/speech-to-text/models/assemblyai and
https://www.assemblyai.com/blog/transcription-at-scale

## Features we will want

- **Word-level timestamps.** Response `words[]`, each with `text`, `start`, `end`
  in ms, and `confidence`. Timestamps are relative to the file start at 0, so
  Timeline offset mapping is a later concern, not a transcription concern.
- **Custom vocabulary / word boost.** The API accepts a boost word list. Feeding a
  team's Team Keywords in should sharpen later callout detection.
Source, https://www.assemblyai.com/docs/pre-recorded-audio/getting-started/transcribe-an-audio-file.md

## What this means for our design

- No batch endpoint, so a `completed` session with 5 player AODs is 5 submissions.
  A per-recording queued job is the natural unit.
- We do not need our own queue to satisfy AssemblyAI's concurrency limit, they
  queue for us. We still want a Laravel queue for retries, backpressure, and
  decoupling the HTTP request from minutes-long work. `.env` already has
  `QUEUE_CONNECTION=database` and the `jobs` table migrated.
- A "processing" phase is real and worth surfacing. Open question is where it
  lives, a new `Session` status, a status on a `transcripts` row, or a
  participant-status step. See the milestone grill.
