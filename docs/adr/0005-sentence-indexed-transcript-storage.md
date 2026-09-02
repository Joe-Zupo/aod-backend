# 5. Sentence-indexed transcript storage and the captions endpoint

The transcription milestone (ADR 0004) stores `transcript_words` parsed from the
`words[]` array of the `GET /v2/transcript/{id}` response, and exposes nothing to
read them. The frontend needs the transcript as sentences, grouped and indexed
the way AssemblyAI's `GET /v2/transcript/{id}/sentences` endpoint returns them,
to drive a caption display.

## Decision

### Sentences are the ingest path, not a parallel structure

`PollTranscription` stops writing `transcript_words`. On `completed` it stores
only `text`, `language_code`, `confidence`, `audio_duration_ms`, then dispatches
a new `FetchTranscriptSentences` job.

`FetchTranscriptSentences` calls `GET /v2/transcript/{id}/sentences` and, in one
transaction, writes:

- `transcript_sentences` — one row per sentence: `transcript_id`, `position`
  (transcript order), `text`, `start_ms`, `end_ms`, `confidence`.
- `transcript_words` — unchanged columns plus `transcript_sentence_id` and a
  `sentence_position` (index within the sentence). The existing transcript-order
  `position` stays.

The words nested in the sentences response are authoritative for
`transcript_words`. `raw_response` still holds the original `words[]` for audit.
A divergence in word count or spans between the two logs a warning; it does not
fail the job.

Per-AOD job chain becomes:
`SubmitTranscription -> PollTranscription -> FetchTranscriptSentences`.
The comm-event milestone (ADR 0006) appends `DetectCommEvents` after it.

### The captions endpoint

`GET /sessions/{session}/captions` returns per-player caption tracks. Shape
mirrors the sentences endpoint with repo conventions: `start_ms` / `end_ms` /
`audio_duration_ms` instead of `start` / `end` / `audio_duration`, and the
always-null `speaker` / `channel` fields are dropped (`speaker_labels` is off —
each AOD is one mic).

```
{
  "session_id": 1,
  "tracks": [
    {
      "participant_id": 10,
      "user_id": 42,
      "status": "completed",
      "language_code": "en",
      "confidence": 0.94,
      "audio_duration_ms": 812000,
      "sentences": [
        {
          "position": 0,
          "text": "...",
          "start_ms": 1200,
          "end_ms": 3400,
          "confidence": 0.95,
          "words": [
            { "position": 0, "text": "...", "start_ms": 1200, "end_ms": 1500, "confidence": 0.98 }
          ]
        }
      ]
    }
  ]
}
```

A `failed` or empty transcript still yields a track: its `status` and no
`sentences`. The endpoint is gated 409-while-`processing`, served from
`timeline_ready` onward. The `timeline_ready` state and that gate land in ADR
0006; until then a completed session never leaves `processing`, so this endpoint
is only reachable once both milestones are in. They ship together, storage
first.

## Considered options

- **Keep parsing `transcript_words` from the transcript `words[]` and add
  `transcript_sentences` as an independent structure.** Rejected. Two word views
  to keep in sync, and caption rendering and comm-event detection would read
  different structures for the same tokens.
- **Store the raw sentences payload as JSON on `transcripts`, no new tables.**
  Rejected. The comm-event milestone queries and foreign-keys individual words; a
  blob makes every detection run a scan and parse.
- **Fetch sentences inside `PollTranscription`'s `completed` branch.** Rejected.
  It makes transcript completion depend on a second provider endpoint. A separate
  job keeps each step single-purpose and independently retryable, and the
  session-advance check (ADR 0006) already waits on sentences being done, so the
  small delay costs nothing.
- **One merged caption track for the whole session.** Rejected. Each AOD is one
  player's mic; a merged track needs offset-correct interleaving that the
  zero-offset assumption (ADR 0004) cannot provide. A merged view can be built on
  the per-player storage later.

## Consequences

- `transcript_words` is written by `FetchTranscriptSentences`, not
  `PollTranscription`. A transcript sits `completed` with no words for the short
  window between the two jobs; consumers must tolerate that.
- Two provider GETs per transcript on the happy path: the final poll and the
  sentences fetch.
- The captions endpoint is unreachable in isolation until ADR 0006 adds the
  `timeline_ready` transition. The two milestones are a pair.
- If AssemblyAI ever changes sentence segmentation, re-running
  `FetchTranscriptSentences` rebuilds `transcript_sentences` and
  `transcript_words` wholesale from `raw_response` is not enough — a fresh
  sentences call is needed. A re-detection path is a follow-up (see ADR 0006).
