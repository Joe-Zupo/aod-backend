# AOD Backend

API backend for the Audio-on-Demand (AOD) Communication Analysis framework: esports teams record per-player audio and gameplay video during scrims, transcribe and analyze communication, and cross-reference it against game events for coach-led post-game review.

## Language

**Session**:
A single recorded game (one scrim or match), from lobby through completion. Scope is deliberately narrow for this prototype: one Session always covers exactly one game, never a multi-game block (e.g. a Bo3 or a night of several scrims).
_Avoid_: scrim, match, game (when referring to the system entity)

**Timeline**:
The synchronized data spine of a Session — the canonical clock that AOD, VOD, and Riot match data are all aligned to via millisecond offsets.

**Session status**:
A Session's lifecycle state: `queuing` → `in_progress` → `completed`, or `cancelled` — reachable from either `queuing` (before recording starts) or `in_progress` (aborting a run already underway). The `queuing` → `in_progress` transition is a Coach starting the Session, and it is also the point per-participant recording begins — the Session Participant's own status machine that governs that is a separate concern. A Session reaches `completed` only when a Coach declares it stopped **and** at least one Session Participant's AOD and VOD have been delivered: coach action and real data are both required, neither alone suffices. Aborting an `in_progress` Session never persists partial AOD/VOD: recordings only become server-side records once a session actually reaches `completed`, so cancelling mid-run discards nothing that was ever saved.
_Avoid_: lobby, waiting (used by the design flowchart, but `queuing` is the canonical term going forward)

**Team Member**:
A user's ongoing relationship to a team (active, pending, or removed), independent of any particular Session.

**Online** (of a User):
Reflects whether a user is authenticated since their last logout — set the moment they log in or register (which also issues a token), cleared the moment they log out. Not a live connection/presence signal: a user who closes the app without logging out stays Online until they explicitly log out again. Broadcast to a user's active team only; a teamless user has no one authorized to see it.
_Avoid_: presence, active (as in "actively connected") — neither implies the auth-boundary meaning this term actually has

**Session Participant**:
A Team Member's participation in one specific Session. Its role is a snapshot of the member's team role at join time — it does not itself grant or change any authority.

**Main Coach**:
A team's sole designated leader. Only the Main Coach can manage team membership (approve/reject join requests, remove members). If the Main Coach leaves the team, the team disbands.

**Assistant Coach**:
A Coach-role team member without leadership authority over team membership, but with equal standing to the Main Coach for running Sessions (any active Coach, main or assistant, may create a Session).

**Team Settings**:
A team's live, single configuration for detection tuning (dead-air threshold, keyword list). Editing it never retroactively changes analysis already produced for a `completed` Session.

**Team Keyword**:
One configured single word a team wants detected in transcripts, tagged with a Category. Callouts read naturally as phrases (see the Category examples below), but detection in this prototype matches individual transcript tokens, so a Team Keyword is always one word — no spaces. Multi-word phrase matching is deferred (see `docs/adr/0001-single-word-team-keywords.md`).
_Avoid_: phrase

**Category** (of a Team Keyword / Callout Detection):
Fixed taxonomy of exactly two values, grounded in the communication-effectiveness research this project builds on:
- **Informative** — shares a current game-state fact the speaker knows that teammates may not (e.g. "enemy is planting", "2 enemies heard in main").
- **Declarative** — states the initiative the speaker is or will be taking (e.g. "popping a flash", "smoking heaven and CT").

**Dead Air**:
A stretch of a Session where no team member communicated at all, evaluated across the whole team's combined audio — not per individual player.
