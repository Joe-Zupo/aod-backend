# 2. Per-session participant consent

Every Session Participant row carries a `participant_status` that runs
`needs_consent` -> `ready` -> `recording` -> `completed`, forward only and one
step at a time. A player joins needing consent and reaches `ready` by calling
the consent endpoint; a Coach joins already `ready`. Starting a Session now
requires every present player to be `ready` (replacing the earlier "at least
one player has joined" guard), and the start sweep moves only players into
`recording`.

## Considered options

- **Team-level or account-level consent that carries across Sessions.**
  Rejected. The recording boundary is the Session, so consent is scoped to the
  Session too. It stays current and legible, and a player who leaves and
  rejoins is asked again rather than inheriting a stale agreement.
- **A Coach override to start past a player who has not consented.** Rejected.
  It would record someone who has not agreed. The player leaves, or the Coach
  cancels the Session. There is no skip or force-ready path.
- **Sweeping Coaches into `recording` alongside players.** Rejected. Only
  players produce AOD and VOD; a Coach's row stays at `ready` for the whole
  run.

## Consequences

- `recording` -> `completed` has no driver yet. It lands with the Session
  completion endpoint (#8), which delivers each participant's AOD and VOD.
- The machine reads the role snapshot on the row, not the member's live team
  role. A member promoted to Coach mid-Session is still a player to their
  existing participant row.
- In-flight consent revocation and picking up a late-consenting player for
  recording are both out of scope.
