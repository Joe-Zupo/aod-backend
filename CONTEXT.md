# AOD Backend

API backend for the Audio-on-Demand (AOD) Communication Analysis framework: esports teams record per-player audio and gameplay video during scrims, transcribe and analyze communication, and cross-reference it against game events for coach-led post-game review.

## Language

**Session**:
A single recorded game (one scrim or match), from lobby through completion. Scope is deliberately narrow for this prototype: one Session always covers exactly one game, never a multi-game block (e.g. a Bo3 or a night of several scrims).
_Avoid_: scrim, match, game (when referring to the system entity)

**Timeline**:
The synchronized data spine of a Session — the canonical clock that AOD, VOD, and Riot match data are all aligned to via millisecond offsets.

**Session status**:
A Session's lifecycle state: `queuing` → `in_progress` → `completed`, or `cancelled` — reachable from either `queuing` (before recording starts) or `in_progress` (aborting a run already underway). A Coach starts the Session to move it from `queuing` to `in_progress`, which is allowed only once every present player has given Consent. That transition is also when recording begins for every consented player. The per-participant recording lifecycle is a separate term (see **Session Participant status**). A Session reaches `completed` only when a Coach declares it stopped and at least one Session Participant's AOD and VOD have been delivered. A stop declaration alone does not complete a Session, and neither does data arriving without one. Aborting an `in_progress` Session never persists partial AOD/VOD: recordings only become server-side records once a session actually reaches `completed`, so cancelling mid-run discards nothing that was ever saved.
_Avoid_: lobby, waiting (used by the design flowchart, but `queuing` is the canonical term going forward)

**Team Member**:
A user's ongoing relationship to a team (active, pending, or removed), independent of any particular Session.

**Online** (of a User):
Reflects whether a user is authenticated since their last logout — set the moment they log in or register (which also issues a token), cleared the moment they log out. Not a live connection/presence signal: a user who closes the app without logging out stays Online until they explicitly log out again. Broadcast to a user's active team only; a teamless user has no one authorized to see it.
_Avoid_: presence, active (as in "actively connected") — neither implies the auth-boundary meaning this term actually has

**Session Participant**:
A Team Member's participation in one specific Session. Its role is a snapshot of the member's team role at join time — it does not itself grant or change any authority. Where the participant sits in the recording lifecycle is tracked separately as **Session Participant status**.

**Session Participant status**:
Where one Session Participant sits in the recording lifecycle for their Session: `needs_consent`, then `ready`, then `recording`, then `completed`. It only ever moves forward, one step at a time. A player joins at `needs_consent` and reaches `ready` by giving Consent. A Coach joins at `ready` and never records, so a Coach's status stays `ready` for the whole Session. Every consented player moves to `recording` when the Coach starts the Session. `completed` marks a participant whose AOD and VOD have been delivered, which happens as part of Session completion.
_Avoid_: state, stage

**Consent** (of a Session Participant):
A player's explicit agreement to be recorded in one Session. Giving it moves their Session Participant status from `needs_consent` to `ready`. It is asked once per Session and every time: a player who leaves and rejoins gives it again. A Coach has nothing to consent to. There is no Coach override to start a Session past a player who has not consented; that player leaves, or the Coach cancels the Session.
_Avoid_: opt-in, waiver, agreement

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
