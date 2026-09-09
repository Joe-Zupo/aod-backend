# 10. Timeline management

> Amends ADR 0006: adds review, authorship and reply columns to the three
> timestamp tables, and reaffirms that no timestamps supertype is built.
> Amends ADR 0008 and ADR 0009: `annotations` gains `parent_id` and the
> `note` and `reply` topics; humans (coach or player) author rows.
> Extends the session lifecycle: a fifth status, `analysis_ready`, after
> `timeline_ready`.

Communication events (ADR 0006), game events (ADR 0007), game-state alignment
(ADR 0008) and dead-air detection (ADR 0009) build every automated axis of the
timeline. What is missing is the human layer. A session reaches `timeline_ready`
and its read endpoints serve any team member at once, so a player sees the raw
automated view with no coach having looked at it, a coach cannot correct a
mis-detected callout or a wrong game-event time, and the system's annotations
cannot be discussed.

This milestone is the coach's review pass. While a session is `timeline_ready`
the timeline is coach-only. Coaches mark timestamps reviewed, create and edit and
delete timestamps, write their own notes, and reply to notes including the
system's. When every timestamp is reviewed a coach marks the session
`analysis_ready`, and only then can players read the timeline and reply.

## Decision

### The `analysis_ready` status

A fifth `app_sessions.status` value, after `timeline_ready`. `timeline_ready`
now means "processed, under coach review"; `analysis_ready` means "signed off,
visible to the team". Two coach-triggered transitions move between them, and
`reanalyze()` still drops back to `processing`.

`POST /sessions/{session}/analysis-ready` is any active Coach. It refuses with
422 unless every `comm_events`, `game_events` and `dead_air_periods` row for the
session has a non-null `reviewed_at`; the response carries the outstanding
count. A session with no timestamps is vacuously all-reviewed and may be marked.
The handler locks the session row, re-checks the from-status and the
all-reviewed condition under the lock, updates, and fires the existing
`SessionStatusChanged` broadcast, the same shape as `start()`, `complete()` and
`reanalyze()`.

`POST /sessions/{session}/reopen-review` is any active Coach. It moves
`analysis_ready` back to `timeline_ready` unconditionally, keeps every existing
`reviewed_at`, and broadcasts. Players lose timeline access again until the next
`analysis-ready`.

### Review, authorship and creation live on the timestamp rows

No timestamps supertype. ADR 0006 collapsed the ER diagram's
`TIMESTAMPS -> EVENTS` nesting because only one event kind existed; three kinds
exist now, but they still have different shapes and different lifecycles.
`DetectCommEvents` and `DetectDeadAir` rebuild two of the three wholesale, and a
supertype would force a shared identity those rebuild jobs have to work around.
`annotations` already points at any kind polymorphically, so nothing else needs
the parent table either.

Each of `comm_events`, `game_events` and `dead_air_periods` gains three nullable
columns:

- `reviewed_at`, a timestamp. Null means the row still needs a coach's eyes.
- `reviewed_by`, a nullable FK to `users`, `nullOnDelete`. Who signed off.
- `created_by`, a nullable FK to `users`, `nullOnDelete`. Null means
  system-generated; non-null means a coach authored the row by hand.

`comm_events.padding_ms` and `dead_air_periods.dead_air_threshold_ms` become
nullable. They record the detection-run parameters, so a coach-created row
carries null there, which reads correctly as "not produced by a run".

There is no backfill. Existing timestamps get `reviewed_at` null, so a session
already at `timeline_ready` correctly shows nothing reviewed, and `created_by`
null, so they read as system rows.

### What "reviewed" means

`reviewed_at` and `reviewed_by` are a record, not a latch. A coach may clear a
row's `reviewed_at` to un-review it. Editing any of a timestamp's own fields
clears its `reviewed_at`, because the thing the coach signed off on has changed;
adding or editing an annotation or a reply on the timestamp does not, because the
timestamp itself is untouched.

A coach-created timestamp starts reviewed, stamped `reviewed_by` and
`reviewed_at` to its author at creation. A deliberate creation is its own
review, and this keeps a coach's own additions from blocking `analysis_ready`.

`POST /sessions/{session}/timestamps/review-all` stamps every row across the
three tables whose `reviewed_at` is null to the acting Coach at `now()`. It is
idempotent and never overwrites an earlier reviewer or time.

### Editable fields

A coach may change any field that will not leave the timeline in a shape the
rest of the system cannot read. That is a per-kind whitelist, not "anything".

| kind | editable |
| --- | --- |
| `game_event` | `type` (in `GameEvent::TYPES`), `side` (`ally` / `enemy` / null, and null iff the type is a round outcome), `match_time_ms`, `round_number`, `note` |
| `comm_event` | `start_ms`, `end_ms`, `content`, `communication_type`, `is_redundant` |
| `dead_air_period` | `start_ms`, `end_ms` |

Frozen everywhere: `game_events.source`, `game_events.raw`, `session_id`,
`comm_events.transcript_id` (a callout cannot move between players),
`comm_events.padding_ms`, a communication event's `callout_detections`, and
`dead_air_periods.dead_air_threshold_ms`.

Cross-cutting rules: `start_ms` is at least 0, `end_ms` is at least `start_ms`,
and an interval's `end_ms` is at most the session window. A row's kind cannot
change; the coach deletes and recreates. A coach edit never re-runs a detection
pass, and never rewrites a system annotation.

### Coach-created timestamps

`POST /sessions/{session}/timestamps` accepts `type` of `communication_event`
or `dead_air` only. Game events come from the match feed; a coach cannot invent
one.

A coach `communication_event` needs a `participant_id` (it is a per-player
callout, like every system communication event), a `communication_type` the
coach picks freely from `informative` / `declarative` / `compound`, a non-empty
`content`, and a span. `compound` is a derived property for a detected event
(both keyword categories present) but a first-class choice for a coach row.
`is_redundant` defaults false, `padding_ms` is null, and there are no
`callout_detections`. It renders in that participant's `timestamps[]` beside the
system rows.

A coach `dead_air` period needs a span. `dead_air_threshold_ms` is null. It
renders in the top-level spine through the existing resource.

Both rows are stamped `created_by` and reviewed to the acting Coach.

### Annotations and replies

Humans author annotations now, and any annotation can have replies. A coach may
while the session is `timeline_ready` or `analysis_ready`; a player only once it
is `analysis_ready` (the same rule as replies, and the same `viewTimeline` gate
means a player can only annotate a timeline they can see).

A note is an `annotations` row with `topic` `note`, `user_id` the author,
`assessment` null, `game_event_ids` `[]`, `parent_id` null. It is free text on
one timestamp.

A reply is an `annotations` row with `topic` `reply`, `user_id` the author
(never null, replies are always human), `annotatable` copied from the parent,
and `parent_id` pointing at a top-level annotation. Replies are one level: a
reply's parent is never another reply. A reply can hang off a system annotation
(`game_state_alignment` or `dead_air`) exactly as off a note.

`annotations` gains `parent_id`, a nullable self-FK, `cascadeOnDelete`. Deleting
a top-level annotation removes its replies. Deleting a timestamp removes its
whole annotation subtree, done explicitly in the delete path because the
polymorphic `annotatable` has no database cascade.

`PATCH` and `DELETE /annotations/{annotation}` are the author's only, and only
for a `note` or a `reply`. A system annotation (`user_id` null) is
immutable and returns 403; a coach who disagrees replies to it or deletes the
timestamp under it.

### Access control

A new `SessionPolicy::viewTimeline` ability: an active team member, and either an
active Coach or a session at `analysis_ready` or a later read state. The
`timeline` and `timeline-summary` endpoints authorize against it. A player
hitting them while the session is `timeline_ready` gets a 403, which reads as
"not yet yours to see" rather than the transient 409 the `processing` state
uses.

`GET /sessions/{session}` and `GET /sessions/{session}/captions` keep the plain
active-member rule. The session's existence and a player's own transcript are
not "the timeline under review".

Every coach management action is any active Coach, main or assistant, matching
every coach action already in the system. Players may create replies, and only
once the session is `analysis_ready`; coaches may reply from `timeline_ready`
on. Coach notes are visible to every team Coach during `timeline_ready` and to
everyone once `analysis_ready`.

### `reanalyze()` clears the review

`reanalyze()` now runs from `timeline_ready` or `analysis_ready` and still lands
in `processing`. On the way it clears every timeline-management artifact: all
`reviewed_at` and `reviewed_by` on the three tables, every timestamp with a
non-null `created_by`, and every non-system annotation (notes and every
reply). A re-analysis means the machine's view of the session has changed, so
the human review starts over. A coach who re-tunes keywords is knowingly
discarding their pass.

### Endpoint surface

Polymorphic, with `{type}` in `communication_event` | `game_event` | `dead_air`,
the same tokens the timeline payload's `type` field uses. A resolver maps
`{type}` to its model and asserts the row belongs to `{session}`.

- `POST /sessions/{session}/timestamps`
- `PATCH` and `DELETE /sessions/{session}/timestamps/{type}/{id}`
- `POST` and `DELETE /sessions/{session}/timestamps/{type}/{id}/review`
- `POST /sessions/{session}/timestamps/review-all`
- `POST /sessions/{session}/timestamps/{type}/{id}/annotations`
- `PATCH` and `DELETE /annotations/{annotation}`
- `POST /annotations/{annotation}/replies`
- `POST /sessions/{session}/analysis-ready`
- `POST /sessions/{session}/reopen-review`

### Read endpoint changes

`GET /sessions/{session}/timeline`: every entry (a `communication_event` under
`data.participants[].timestamps[]`, a `game_event` or `dead_air` in
`data.game_events[]`) gains `reviewed`, `reviewed_at`, `reviewed_by` and
`created_by`, always present. Every inline annotation gains `id`, `author`
(`{ id, username }` or null for a system row), `parent_id`, and
`replies: [{ id, author, body, created_at }]` nested one level. A player reading
this at `analysis_ready` sees everything, notes and every reply included.

`GET /sessions/{session}/timeline-summary`: `data.team` gains
`review: { reviewed, total, complete }` and each `data.participants[]` gains
`review: { reviewed, total }`, present only when the requester is a Coach and
the session is `timeline_ready`. A participant's `total` counts that player's
communication events. The team `total` counts every communication event across
all participants, every game event and every dead-air period, system and
coach-created, un-annotated dead-air periods included, so the parts reconcile to
the team figure. `complete` is `reviewed == total`.

## Considered options

- **Build the timestamps supertype table now.** Rejected. The three kinds still
  differ in shape and lifecycle, two are rebuilt wholesale by their detection
  jobs, and a supertype forces a shared identity those jobs work around.
  `annotations` and the review columns do not need a parent table.
- **A `timeline_reviews` side table, polymorphic to any kind.** Rejected. Review
  state is one timestamp and one FK per row; three nullable columns on the
  tables that already exist is less machinery than a fourth table with its own
  polymorphic join, and `created_by` has to live on the rows regardless.
- **`reanalyze()` preserves coach work, or is forbidden once review starts.**
  Rejected. Preserving it means coach annotations orphaned on rebuilt system
  rows and edited spans silently reverted; forbidding it strands a coach who
  genuinely needs to re-tune. Wiping the review and saying so plainly is the
  honest option.
- **A separate `annotation_replies` table.** Rejected. A reply is an annotation
  with a parent; `parent_id` on the existing table and a one-level rule keeps
  one entity, one set of permissions, one render path.
- **Multi-level reply threads.** Rejected for v1. One level covers "discuss this
  finding"; nesting adds a tree to render and a depth to police for no clear
  need yet.
- **Retro-correct system annotation prose and `game_event_ids` after a coach
  edit.** Rejected. Those are a snapshot of what the machine saw; rewriting them
  on a manual edit blurs the line between the automated read and the human one.
  The coach replies or deletes instead.
- **Coach-created `game_event`s.** Rejected. Game state comes from the match
  feed; a hand-entered kill is a different kind of claim and there is no use for
  it yet.
- **Gate `show` and `captions` behind the review as well.** Rejected. A player
  seeing that a session exists and is under review, and re-reading their own
  callouts, does not leak the analysed timeline.

## Consequences

- `timeline_ready` changes meaning. It was "readable by the team"; it is now
  "readable by coaches, under review". A player at `timeline_ready` gets a 403
  on the timeline endpoints where they used to get a 200. Clients must handle
  the new `analysis_ready` status and the 403 window.
- Every read of `timeline` and `timeline-summary` now also loads review and
  authorship state and the reply subtree. The eager-load lists on both endpoints
  grow.
- `annotations` gains its third and fourth topics (`note`, `reply`) and
  its first non-null `user_id` rows. Code that assumed `user_id` null or a
  system topic must widen.
- The two snapshot columns going nullable means every reader of
  `comm_events.padding_ms` or `dead_air_periods.dead_air_threshold_ms` must
  tolerate null. Today only the detectors and their tests read them.
- `reanalyze()` is now destructive to hours of coach work. The endpoint doc and
  this ADR say so; a confirmation step in the client is a product decision, not
  a backend one.
- A coach-edited timestamp can leave a system annotation's prose describing the
  old values. That is accepted: the annotation is the machine's account, the
  coach's edit and any reply are the human's.

## Amendment, 2026-09-09, issue #16 implementation

Settled during the build. Nothing reverses the decision above; these fill in the
seams.

### Three policy abilities, not one per action

`SessionPolicy` gained `viewTimeline` (the read gate: active member, and either
an active Coach or a session at `analysis_ready`), `manageTimeline` (every
mutation: an active Coach on a session that is `timeline_ready` or
`analysis_ready`) and `annotateTimeline` (a note or a reply: a Coach any time from `timeline_ready`,
a player only at `analysis_ready`). The `/annotations/{annotation}` routes carry
no `{session}`, so `Annotation::timelineSession()` walks the polymorphic
`annotatable` back to its session for the authorize call; author-only edit and
delete are a plain ownership check, not a policy method.

### A game event can carry a note

`GameEvent` gained an `annotations()` morphMany. ADR 0009's "an annotation never
attaches to a game event" held so that dead-air periods needed their own table;
that reason is untouched (the system still never annotates a game event). A
hand-authored note on a game event is a different thing and is allowed.

### Shared code

`App\Models\Concerns\Reviewable` holds `reviewed_at` / `reviewed_by`, the
`reviewer` / `creator` relations, `markReviewed()` / `clearReview()` and the
`reviewed` / `unreviewed` scopes, mixed into all three timestamp models.
`App\Support\TimelineTimestamp` resolves the `{type}` segment
(`communication_event` / `game_event` / `dead_air`) to its model and asserts the
row belongs to the route's session. `TimelineAnnotationResource::tree()` nests a
flat annotation collection into the payload's one-level reply shape.

### Session methods

`markAnalysisReady()`, `reopenReview()`, `reviewCounts()`,
`allTimestampsReviewed()`, `windowMs()`, `commEventsQuery()` and the private
`discardTimelineManagement()` (called at the top of `reanalyze()`) live on
`Session`. `reanalyze()`'s from-status check widened to accept `analysis_ready`.

### Endpoint consolidation (folded in before commit)

The surface this ADR first described was trimmed during the build. Two merges,
no behaviour change:

- **`POST /sessions/{session}/transitions`** replaces `POST .../analysis-ready`,
  `POST .../reopen-review`, `POST .../cancel` and `POST .../reanalyze`. The body
  is `{ "to": "cancelled" | "reanalyze" | "analysis_ready" | "timeline_ready" }`
  and the controller `match`es it to `Session::cancel()` / `reanalyze()` /
  `markAnalysisReady()` / `reopenReview()`. Three of the four `to` values are
  target statuses; `reanalyze` is named as the action because its rebuild lands
  in `processing`, which is not a state a client asks for directly. Every
  from-status guard, 422 message, broadcast and job dispatch stays on the model.
  `to: "reanalyze"` returns 202 (it queues detection); the rest return 200.
  `to: "in_progress"` returns 422 pointing at `POST .../start`. Authorised by a
  single `SessionPolicy::transition` (any active Coach); the `cancel` and
  `reanalyze` policy abilities are gone.
  `start` and `complete` keep their own routes: `start` belongs to the recording
  lifecycle and has a consent precondition, `complete` is a multipart upload.

- **`PUT /sessions/{session}/timestamps/{type}/{id}/review`** with
  `{ "reviewed": true | false }` replaces the `POST` / `DELETE` pair on that
  path. `review-all` is unchanged.

The reads (`show`, `captions`, `timeline`, `timeline-summary`) stay four separate
GETs: `show` and `captions` are member-visible while `timeline` and
`timeline-summary` are coach-only during `timeline_ready`, and one endpoint
cannot carry two auth rules or a body that changes shape by caller.
