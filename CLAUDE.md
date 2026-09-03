## Agent skills

### Issue tracker

Issues and specs live as GitHub issues (`Joe-Zupo/aod-backend`), via the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default label vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.

### Game-event ingest

When the user pastes a free-form `side` / `timestamp` / `game_event` list, transcribe it into the ADR 0007 `game_events` fixture for `POST /sessions/{session}/complete`. Grammar, synonym table, and procedure in `docs/agents/game-event-ingest.md`.
