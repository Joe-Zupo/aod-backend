<?php

namespace App\Http\Controllers;

use App\Exceptions\SessionTransitionException;
use App\Http\Requests\CompleteSessionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\SessionResource;
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
     * Create a Session and its Timeline together, atomically, for the given team,
     * and add the creating Coach as its first Session Participant. Restricted to
     * any active Coach, main or assistant. Rejected if the team already has a
     * non-terminal (queuing/in_progress) session.
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
     * of the session's team.
     */
    public function show(Request $request, Session $session): JsonResponse
    {
        $this->authorize('view', $session);

        return $this->success('Session retrieved.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
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
     * Cancel Session
     *
     * Transition a queuing or in_progress session to cancelled. Restricted to
     * any active Coach on the session's team, not just its creator.
     */
    public function cancel(Request $request, Session $session): JsonResponse
    {
        $this->authorize('cancel', $session);

        try {
            $session->cancel();
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Session cancelled.', [
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

        try {
            $session->complete($entries);
        } catch (SessionTransitionException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success('Session completed.', [
            'session' => new SessionResource($session->load('activeParticipants.user')),
        ]);
    }
}
