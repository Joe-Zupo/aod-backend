# Game-event ingest

When the user pastes a free-form list of game events from a match, transcribe it into the `game_events` JSON array that `POST /sessions/{session}/complete` accepts. This file holds the input grammar, the prose-to-`type` synonym table, and the transcription procedure. The element schema, validation, and valence rules live in `docs/adr/0007-manual-game-event-ingest.md` and are not repeated here.

## Trigger

The user pastes one or more blocks of this shape, with the timestamp aligned to the VOD recording.

```
side       : ally | enemy
timestamp  : m:ss
game_event : <prose>
round      : <int>
note       : <text>        optional
```

## Input grammar

- `side` is required on blocks that resolve to `kill`, `spike_plant`, or `spike_defuse`. It is optional on `death` (defaults to `ally`) and ignored on `round_win` / `round_lost`.
- `timestamp` is `m:ss`, always under `60:00`.
- `game_event` is prose, resolved to one `type` through the synonym table.
- `round` is required on every block and fills `round_number`. The endpoint schema keeps `round_number` nullable for Riot compatibility, but this grammar always supplies it.
- `note` is optional and copied verbatim. Emit `null` when the block omits it.

## Mapping

### timestamp to match_time_ms

Convert `m:ss` to integer milliseconds, literally. `1:23` becomes `83000`. Match start equals Timeline zero under the zero-offset assumption (`docs/adr/0004-transcription-pipeline.md`). No VOD lead-in offset is applied.

### game_event to type

Resolve the prose to exactly one enum value using the table below. Match on the verb, not the actor. The `side` line, not the prose, carries which team acted.

| `type`         | resolves from                                                        |
| -------------- | ------------------------------------------------------------------- |
| `kill`         | kill, frag, pick, "got one", "one down", "dropped one"            |
| `death`        | death, "we died", "lost a player", "ally down", "got picked"      |
| `spike_plant`  | plant, planted, planting, "spike down"                             |
| `spike_defuse` | defuse, defused                                                     |
| `round_win`    | round win, won round, "won the round", "round won", "allies win", "we win" |
| `round_lost`   | round loss, lost round, "lost the round", "round dropped", "enemies win" |

One block is one event. "Double kill" is two blocks, not one block expanded.

A block whose `game_event` resolves to no row is an abort (see Procedure).

### side

`side` is the team the event favours, not the performer (`docs/adr/0007-manual-game-event-ingest.md`). Take the user's value as already meaning that.

- `kill` with `ally` is our frag. `kill` with `enemy` is theirs.
- `death` is only ever our loss. Emit `"side": "ally"` whether the block states it or omits it. A `death` block with `side: enemy` is a contradiction, since that is a `kill` for our team, and is an abort.
- On `round_win` / `round_lost`, drop `side` entirely even when the block supplies one.

## Procedure

1. Parse every block in the batch.
2. Echo a numbered table with one row per block, showing the raw `game_event`, the resolved `type`, `side`, `match_time_ms`, `round_number`, and `note`. Flag two conditions. `DUPLICATE` when a row shares `match_time_ms`, `type`, and `side` with another. `ABORT` when the prose resolves to no `type`, or a `death` carries `side: enemy`.
3. If any row is `ABORT`, write nothing. List the abort rows and wait for the user to fix and resend the batch.
4. With no aborts, wait for the user's explicit OK on the table.
5. On OK, merge the batch into the match's fixture file, sort the full array ascending by `match_time_ms` with input order breaking ties, and write it. Keep `DUPLICATE` rows. Append the raw paste to the `.source.txt` sidecar under a dated separator.
6. When the batch extended an existing file, re-echo the full merged array.

## Files

- One file per match at `tests/Fixtures/GameEvents/<slug>.json`, a bare JSON array of ADR 0007 elements. The current prepared match is `batch13-prepared-match.json`. Rename it to `SESSION_0NN.json` once a Session exists for it.
- `tests/Fixtures/GameEvents/<slug>.source.txt` holds every raw paste for that match, verbatim, and is not read by tests.
- A new match is a new file. Batches never cross matches.

## Submitting the file

The fixture file is the `game_events` value on `POST /sessions/{session}/complete`, sent in the multipart body beside `players[]`. Either form is accepted:

- the `.json` file uploaded directly as `game_events`, or
- its contents as a `game_events` text field holding the JSON string.

`game_events` is required: an absent or empty payload is refused with 422.

## Output element

Per `docs/adr/0007-manual-game-event-ingest.md`. `round_number` is always present in files authored here.

```
{ "type": "spike_plant", "side": "ally", "match_time_ms": 41000, "round_number": 3, "note": null }
```

Omit the `side` key entirely on `round_win` / `round_lost`.

## Contract changes made for the prototype

`game_events` is mandatory on completion, which the original ADR 0007 and issue #13 left optional. Landed with #13 and recorded in the ADR 0007 amendment (2026-09-03):

- Presence is enforced in `SessionController::complete()` (422, absent or empty). Shape is `CompleteSessionRequest`'s job, all-or-nothing.
- `round_number` stays nullable in the endpoint schema and table for Riot compatibility, though this grammar always supplies it.
- `side` on a `round_win` / `round_lost` element is rejected, not stripped.
- The timeline endpoint surfaces game events as a top-level `data.game_events[]`, not interleaved into a per-participant list.
- `CONTEXT.md` Game Event and Timestamp entries updated to match.
