<?php

namespace App\Http\Controllers;

use App\Exceptions\SessionNotReadableException;
use App\Exceptions\SessionTransitionException;
use App\Http\Requests\CompleteSessionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\SessionCaptionsResource;
use App\Http\Resources\SessionResource;
use App\Http\Resources\SessionTimelineResource;
use App\Http\Resources\SessionTimelineSummaryResource;
use App\Models\Session;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SessionController extends Controller
{
    /**
     * Session List
     *
     * Return the team's current non-terminal (queuing or in_progress) session,
     * if any, as `live_session`, and every other session as a paginated,
     * searchable, date-ordered `past_sessions` list. Never loads participants.
     * Restricted to any active member of the team.
     */
    public function index(Request $request, Team $team): JsonResponse
    {
        $this->authorize('viewAny', [Session::class, $team]);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $liveSession = $team->sessions()->nonTerminal()->first();

        $pastSessions = $team->sessions()
            ->when($liveSession, fn ($query) => $query->whereKeyNot($liveSession->id))
            ->when(
                $validated['search'] ?? null,
                fn ($query, $search) => $query->where('session_name', 'like', '%'.$search.'%')
            )
            ->orderBy('created_at', $validated['sort'] ?? 'desc')
            ->paginate($validated['per_page'] ?? 15);

        return $this->success('Sessions retrieved.', [
            'live_session' => $liveSession ? new SessionResource($liveSession) : null,
            'past_sessions' => SessionResource::collection($pastSessions),
            'pagination' => $this->pagePaginationData($pastSessions),
        ]);
    }

    /**
     * Create Session
     *
     * Create a Session for the given team and add the creating Coach as its
     * first Session Participant. Restricted to any active Coach, main or
     * assistant. Rejected if the team already has a non-terminal
     * (queuing/in_progress) session. The Timeline is created later, when the
     * session is completed and enters processing.
     */
    public function store(StoreSessionRequest $request, Team $team): JsonResponse
    {
        $this->authorize('create', [Session::class, $team]);

        $session = Session::createForTeam($team, $request->user(), $request->validated('session_name'));

        if (! $session) {
            return $this->error('This team already has an active session.', 422);
        }

        return $this->success('Session created.', ['session' => new SessionResource($session->load('activeParticipants.user'))], 201);
    }

    /**
     * Session Return
     *
     * Return a session and its participants. Restricted to any active member
     * of the session's team. Refused with 409 while the session is processing:
     * the analysis pipeline is mid-run and there is no coherent session view to
     * return yet. Progress is on the team session index instead.
     */
    public function show(Request $request, Session $session): JsonResponse
    {
        $this->authorize('view', $session);

        $this->assertReadable($session);

        return $this->success('Session retrieved.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }

    /**
     * Session Captions
     *
     * Return one caption track per player who recorded audio: the transcript's
     * status and, when it completed, its sentences with nested words and
     * millisecond spans. A `failed` or empty transcript still yields a track
     * with its status and no sentences. Restricted to any active member of the
     * session's team. Refused with 409 while the session is processing, served
     * from timeline_ready onward.
     */
    public function captions(Request $request, Session $session): JsonResponse
    {
        $this->authorize('view', $session);

        $this->assertReadable($session);

        $session->load([
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.user',
            'participants.aodRecord.transcript.sentences.words',
        ]);

        // Envelope spelled out rather than routed through success() so Scramble
        // resolves the response schema from the Resource's toArray().
        return response()->json([
            'message' => 'Session captions retrieved.',
            'data' => new SessionCaptionsResource($session),
            'code' => 200,
            'error' => false,
        ]);
    }

    /**
     * Session Timeline
     *
     * Return the session, its Timeline, and one entry per participant who
     * recorded, each carrying that player's own communication-event timestamps
     * (with callouts) ordered by start, plus their transcript / AOD / VOD
     * metadata. Recording metadata only, no URLs. Restricted to any active
     * member of the session's team. Refused with 409 while the session is
     * processing, served from timeline_ready onward.
     */
    public function timeline(Request $request, Session $session): JsonResponse
    {
        $this->authorize('viewTimeline', $session);

        $this->assertReadable($session);

        $session->load([
            'timeline',
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.user',
            'participants.aodRecord.transcript.commEvents.calloutDetections',
            'participants.aodRecord.transcript.commEvents.annotations.author',
            'participants.vodRecord',
            'gameEvents.annotations.author',
            'deadAirPeriods.annotations.author',
        ]);

        // Envelope spelled out rather than routed through success() so Scramble
        // resolves the response schema from the Resource's toArray().
        return response()->json([
            'message' => 'Session timeline retrieved.',
            'data' => new SessionTimelineResource($session),
            'code' => 200,
            'error' => false,
        ]);
    }

    /**
     * Session Timeline Summary
     *
     * Return communication frequency, event counts by type (informative /
     * declarative / compound / redundant) and provisional team-aggregate
     * silence metrics, at team and per-player level. Restricted to any active
     * member of the session's team. Refused with 409 while the session is
     * processing, served from timeline_ready onward.
     */
    public function timelineSummary(Request $request, Session $session): JsonResponse
    {
        $this->authorize('viewTimeline', $session);

        $this->assertReadable($session);

        $session->load([
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.user',
            'participants.aodRecord.transcript.commEvents.annotations',
            'gameEvents',
            'deadAirPeriods',
        ]);

        // Envelope spelled out rather than routed through success() so Scramble
        // resolves the response schema from the Resource's toArray().
        return response()->json([
            'message' => 'Session timeline summary retrieved.',
            'data' => new SessionTimelineSummaryResource($session),
            'code' => 200,
            'error' => false,
        ]);
    }

    /**
     * Transition Session
     *
     * A coach-driven state move that carries no payload. `to` names the move:
     *
     * - `cancelled` — abort the session (from `queuing` or `in_progress`).
     * - `reanalyze` — rebuild the timeline from the stored recordings using the
     *   team's current settings; returns 202 and re-opens at `timeline_ready`
     *   once detection finishes. Discards all timeline-management work.
     * - `analysis_ready` — open the reviewed timeline to players (from
     *   `timeline_ready`, once every timestamp is reviewed).
     * - `timeline_ready` — reopen review (from `analysis_ready`).
     *
     * `start` and `complete` are their own endpoints. Any active Coach; whether
     * the move is legal from the session's current status is the model's guard,
     * surfaced as 422. See docs/adr/0010-timeline-management.md.
     */
    public function transition(Request $request, Session $session): JsonResponse
    {
        $this->authorize('transition', $session);

        if ($request->input('to') === Session::STATUS_IN_PROGRESS) {
            return $this->error('Use POST /sessions/{session}/start to begin recording.', 422);
        }

        $to = $request->validate([
            'to' => ['required', Rule::in(Session::TRANSITIONS)],
        ])['to'];

        try {
            match ($to) {
                Session::STATUS_CANCELLED => $session->cancel(),
                Session::TRANSITION_REANALYZE => $session->reanalyze(),
                Session::STATUS_ANALYSIS_READY => $session->markAnalysisReady(),
                Session::STATUS_TIMELINE_READY => $session->reopenReview(),
            };
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Session transition applied.', [
            'session' => new SessionResource($session->fresh()->load('activeParticipants.user')),
        ], $to === Session::TRANSITION_REANALYZE ? 202 : 200);
    }

    /**
     * Join Session
     *
     * Add the authenticated active team member as a Session Participant while
     * the session is queuing, snapshotting their current team role. Idempotent:
     * joining again is a no-op success, not an error. A member who'd
     * previously left is reactivated rather than staying stuck as departed.
     */
    public function join(Request $request, Session $session): JsonResponse
    {
        $this->authorize('join', $session);

        $user = $request->user();

        try {
            $session->joinOrRejoin($user);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent join from the same user: the row
            // now exists either way, so this is still the idempotent success
            // this endpoint promises, not an error.
        }

        return $this->success('Joined session.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }

    /**
     * Record Consent
     *
     * Move the authenticated caller's own participant row from needs_consent
     * to ready. Idempotent once already ready. Restricted to any active member
     * of the session's team; a Coach, a non-participant, or a session past the
     * point of consent is rejected with 422.
     */
    public function consent(Request $request, Session $session): JsonResponse
    {
        $this->authorize('consent', $session);

        try {
            $session->recordConsent($request->user());
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Consent recorded.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }

    /**
     * Start Session
     *
     * Transition a queuing session to in_progress. Restricted to any active
     * Coach on the session's team, not just its creator.
     */
    public function start(Request $request, Session $session): JsonResponse
    {
        $this->authorize('start', $session);

        try {
            $session->start();
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Session started.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }

    /**
     * Complete Session
     *
     * Take an audio and video slot for every recording player, store whatever
     * files the slots hold, then transition the in_progress session to completed
     * and sweep every recording participant to completed. Restricted to any
     * active Coach on the session's team, not just its creator. Rejected with
     * 422 if the session is not in_progress, the submitted player ids do not
     * match the recording roster exactly, or no slot holds both files.
     */
    public function complete(CompleteSessionRequest $request, Session $session): JsonResponse
    {
        $this->authorize('complete', $session);

        $players = $request->validated('players');

        $entries = [];
        foreach (array_keys($players) as $i) {
            $entries[] = [
                'user_id' => (int) $players[$i]['user_id'],
                'audio' => $request->file("players.{$i}.audio"),
                'video' => $request->file("players.{$i}.video"),
            ];
        }

        // input(), not validated(): the game-event rows keep the original
        // payload element in `raw`, so a key the schema does not yet name (a
        // future Riot field, an author annotation) survives the round trip.
        // Validation has already passed by this point.
        $gameEvents = $request->input('game_events') ?? [];

        if ($gameEvents === []) {
            return $this->error('Game events are required to complete a session.', 422);
        }

        try {
            $session->complete($entries, $gameEvents);
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Session completed.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }

    /**
     * The 409 the read endpoints (show, captions, timeline, timeline-summary)
     * share: while a session is `processing` the analysis pipeline is mid-run
     * and there is no coherent view to return. Throws rather than returns so
     * each action stays a single return path; `timeline_ready` and later read
     * states fall through. Rendered as a 409 in bootstrap/app.php.
     */
    private function assertReadable(Session $session): void
    {
        if ($session->status === Session::STATUS_PROCESSING) {
            throw new SessionNotReadableException('This session is still processing.');
        }
    }
}
