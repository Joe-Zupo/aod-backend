# Session Lifecycle & Team Settings

## Problem Statement

Coaches need to configure how their team's communication gets detected (which keywords/phrases matter, how long is "too quiet") and need to run an actual recording session with their roster — neither exists yet. The backend currently only has auth and team membership; there's no way to create a session, join one, or configure detection settings.

## Solution

Add **Team Settings** (a per-team singleton holding the dead-air threshold and a list of categorized **Team Keywords**) and a **Session** lifecycle (with its **Timeline** and **Session Participants**) so a Coach can configure detection, create a session, have the roster join, run it, and reach a `completed` state once real recordings exist.

## User Stories

1. As an active Coach, I want to view my team's current dead-air threshold and keyword list, so that I know how detection is currently configured.
2. As an active Coach, I want to update my team's dead-air threshold, so that detection sensitivity matches how my team plays.
3. As an active Coach, I want to add a keyword or phrase tagged Informative or Declarative, so that the system can detect team-specific callouts.
4. As an active Coach, I want to edit an existing keyword's text or category, so that I can correct misclassifications.
5. As an active Coach, I want to delete a keyword, so that stale callouts stop being tracked.
6. As a non-Coach team member, I want to be blocked from modifying team settings, so that only coaching staff controls detection configuration.
7. As a user outside the team, I want to be blocked from viewing or modifying that team's settings, so that configuration stays private.
8. As an active Coach (main or assistant), I want to create a session for my team, so that we can start a scrim recording.
9. As an active Coach, I want session creation to fail if my team already has a `queuing`/`in_progress` session, so that we never run two at once.
10. As an active team member, I want to see and join a `queuing` session directly, so that I can participate without waiting for an invite.
11. As a user outside the team, I want to be blocked from joining a session, so that only my own team's data is recorded.
12. As an active Coach, I want to start a `queuing` session once at least one player has joined, so that recording can begin.
13. As an active Coach, I want to be blocked from starting a session with zero joined players, so that we don't record an empty session.
14. As an active Coach, I want to cancel a `queuing` session before it starts, so that we can abort a scrim that isn't happening.
15. As an active Coach, I want to be blocked from cancelling a session that's already `in_progress`, so that in-flight recordings aren't silently discarded.
16. As an active Coach, I want to declare an `in_progress` session stopped, so that recording ends and uploads can begin.
17. As the system, I want a session to reach `completed` only once at least one participant's AOD and VOD exist, so that "completed" always means real data exists.
18. As any active Coach on the team, I want to control (start/stop/cancel) a session even if I didn't create it, so that coaching duties aren't blocked by who clicked "create."
19. As a team member, I want to view the current or most recent session and its participants, so that I know what's happening or what was recorded.
20. As a developer, I want Session and Timeline created together atomically, so that a session never exists without its timeline.

## Implementation Decisions

- New tables (per `CONTEXT.md`/ER diagram vocabulary): `team_settings` (1:1 with `teams`; `dead_air_threshold_ms`), `team_keywords` (many:1 with `team_settings`; `keyword`, `category` enum [`informative`, `declarative`], `is_phrase`), `sessions` (`team_id`, `created_by`, `session_name`, `status` enum [`queuing`, `in_progress`, `completed`, `cancelled`], default `queuing`), `timelines` (1:1 with `sessions`), `session_participants` (`session_id`, `user_id`, `participant_role` snapshotted from `team_members.member_role` at join time, `joined_at`/`left_at`).
- `Session`+`Timeline` created together in one transaction on session creation, matching the flowchart.
- `SessionPolicy`: `create`/`start`/`stop`/`cancel` → any active Coach on the team (main or assistant) — same authority tier as running practice, per the earlier role-authorization decision. `view`/`join` → any active team member.
- Enforce one non-terminal (`queuing`/`in_progress`) session per team at creation time — reject with a 422, same pattern as `TeamController`'s existing validation errors.
- `SessionParticipant` rows are created directly on join, no pending/invite state, and only for users with an active `TEAM_MEMBERS` row on that team.
- `completed` requires ≥1 `SessionParticipant`'s AOD **and** VOD to exist — but those tables don't exist yet (see Out of Scope), so this spec can only encode the invariant, not fully wire the transition end-to-end.
- **Inferred, not confirmed:** `TeamSettingsPolicy` follows the same "any active Coach" tier as `SessionPolicy` (configuring detection is coaching work, not team-leadership work like membership management) — flagged for a quick confirm before implementation, since it wasn't explicitly settled during grilling.
- **Unresolved default:** no value was set for a newly-created team's `dead_air_threshold_ms` — implementer needs a placeholder pending a real number from the user.

## Testing Decisions

- Feature/HTTP tests only (`tests/Feature/TeamSettingsTest.php`, `tests/Feature/SessionLifecycleTest.php`), asserting HTTP status + `assertDatabaseHas`, never calling Policy/Controller methods directly. Prior art: `tests/Feature/TeamMembershipTest.php`.
- Cover: Coach-only settings mutation, cross-team isolation, one-active-session-per-team enforcement, join eligibility (active members only), the start/cancel/stop state transitions and their guards, and any-active-Coach-can-control-any-session.

## Out of Scope

- `AOD_RECORDS`, `VOD_RECORDS`, `RIOT_MATCHES`, `EVENTS`, `COMM_EVENTS`, `GAME_EVENTS`, `CALLOUT_DETECTIONS`, `COMM_EVENT_WORDS`, `ANNOTATIONS`, `TIMESTAMPS`, and the AssemblyAI pipeline — later milestones.
- The actual AOD/VOD upload endpoint and the resulting `completed`-transition data check — blocked on the tables above; this spec only establishes the enum and the invariant.
- Multi-game sessions, Team Settings history/versioning, mid-session participant disconnect/reconnect handling.
- Any frontend/UI work.

## Further Notes

- Vocabulary follows [CONTEXT.md](../../CONTEXT.md). An ADR for "one game per session, multi-game deferred" was proposed during grilling but not yet written — worth doing before/alongside this work.
