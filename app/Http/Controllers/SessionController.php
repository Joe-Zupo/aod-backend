<?php

namespace App\Http\Controllers;

use App\Exceptions\SessionTransitionException;
use App\Http\Requests\CompleteSessionRequest;
use App\Http\Requests\StoreSessionRequest;
use App\Http\Resources\SessionResource;
use App\Models\CommEvent;
use App\Models\Session;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        if ($response = $this->refuseWhileProcessing($session)) {
            return $response;
        }

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

        if ($response = $this->refuseWhileProcessing($session)) {
            return $response;
        }

        $session->load([
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.aodRecord.transcript.sentences.words',
        ]);

        $tracks = $session->participants
            ->filter(fn ($participant) => $participant->aodRecord && $participant->aodRecord->transcript)
            ->map(function ($participant) {
                $transcript = $participant->aodRecord->transcript;

                return [
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'status' => $transcript->status,
                    'language_code' => $transcript->language_code,
                    'confidence' => $transcript->confidence,
                    'audio_duration_ms' => $transcript->audio_duration_ms,
                    'sentences' => $transcript->sentences->map(fn ($sentence) => [
                        'position' => $sentence->position,
                        'text' => $sentence->text,
                        'start_ms' => $sentence->start_ms,
                        'end_ms' => $sentence->end_ms,
                        'confidence' => $sentence->confidence,
                        'words' => $sentence->words->map(fn ($word) => [
                            'position' => $word->sentence_position,
                            'text' => $word->word,
                            'start_ms' => $word->start_ms,
                            'end_ms' => $word->end_ms,
                            'confidence' => $word->confidence,
                        ])->values(),
                    ])->values(),
                ];
            })
            ->values();

        return $this->success('Session captions retrieved.', [
            'session_id' => $session->id,
            'tracks' => $tracks,
        ]);
    }

    /**
     * Session Timeline
     *
     * Return the session, its Timeline, an ordered type-tagged timestamp list
     * (currently only communication events, with their callouts), and
     * per-participant transcript / AOD / VOD metadata. Recording metadata only,
     * no URLs. Restricted to any active member of the session's team. Refused
     * with 409 while the session is processing, served from timeline_ready
     * onward.
     */
    public function timeline(Request $request, Session $session): JsonResponse
    {
        $this->authorize('view', $session);

        if ($response = $this->refuseWhileProcessing($session)) {
            return $response;
        }

        $session->load([
            'timeline',
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.aodRecord.transcript.commEvents.calloutDetections',
            'participants.vodRecord',
        ]);

        $timestamps = $session->participants
            ->flatMap(function ($participant) {
                $transcript = $participant->aodRecord?->transcript;

                if (! $transcript) {
                    return [];
                }

                return $transcript->commEvents->map(fn ($event) => [
                    'type' => 'communication_event',
                    'id' => $event->id,
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'communication_type' => $event->communication_type,
                    'is_redundant' => $event->is_redundant,
                    'start_ms' => $event->start_ms,
                    'end_ms' => $event->end_ms,
                    'content' => $event->content,
                    'callouts' => $event->calloutDetections->map(fn ($callout) => [
                        'keyword' => $callout->keyword,
                        'normalized_keyword' => $callout->normalized_keyword,
                        'category' => $callout->category,
                        'start_ms' => $callout->start_ms,
                        'end_ms' => $callout->end_ms,
                        'confidence' => $callout->confidence,
                    ])->values(),
                ]);
            })
            ->sortBy('start_ms')
            ->values();

        $participants = $session->participants
            ->filter(fn ($participant) => $participant->aodRecord !== null)
            ->map(function ($participant) {
                $aod = $participant->aodRecord;
                $vod = $participant->vodRecord;
                $transcript = $aod->transcript;

                return [
                    'participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'transcript' => $transcript ? [
                        'id' => $transcript->id,
                        'status' => $transcript->status,
                        'language_code' => $transcript->language_code,
                        'confidence' => $transcript->confidence,
                        'audio_duration_ms' => $transcript->audio_duration_ms,
                    ] : null,
                    'aod' => $this->recordingMeta($aod),
                    'vod' => $vod ? $this->recordingMeta($vod) : null,
                ];
            })
            ->values();

        return $this->success('Session timeline retrieved.', [
            'session_id' => $session->id,
            'status' => $session->status,
            'timeline' => [
                'id' => $session->timeline?->id,
                'created_at' => $session->timeline?->created_at,
                'duration_ms' => null,
            ],
            'timestamps' => $timestamps,
            'participants' => $participants,
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
        $this->authorize('view', $session);

        if ($response = $this->refuseWhileProcessing($session)) {
            return $response;
        }

        $session->load([
            'participants' => fn ($query) => $query->orderBy('id'),
            'participants.aodRecord.transcript.commEvents',
        ]);

        $recording = $session->participants->filter(
            fn ($participant) => $participant->aodRecord?->transcript !== null,
        );

        $windowMs = (int) $recording
            ->map(fn ($participant) => (int) $participant->aodRecord->transcript->audio_duration_ms)
            ->max();

        $allEvents = $recording->flatMap(fn ($participant) => $participant->aodRecord->transcript->commEvents);

        $participants = $recording->map(function ($participant) use ($windowMs) {
            $events = $participant->aodRecord->transcript->commEvents;

            return [
                'participant_id' => $participant->id,
                'user_id' => $participant->user_id,
                'frequency_per_min' => $this->frequencyPerMin($events->count(), $windowMs),
                'counts' => $this->commEventCounts($events),
            ];
        })->values();

        [$totalTalkMs, $totalSilenceMs, $longestSilenceMs] = $this->silenceProxy($allEvents, $windowMs);

        return $this->success('Session timeline summary retrieved.', [
            'session_id' => $session->id,
            'session_window_ms' => $windowMs,
            'team' => [
                'frequency_per_min' => $this->frequencyPerMin($allEvents->count(), $windowMs),
                'counts' => $this->commEventCounts($allEvents),
                'total_talk_ms' => $totalTalkMs,
                'total_silence_ms' => $totalSilenceMs,
                'longest_silence_ms' => $longestSilenceMs,
            ],
            'participants' => $participants,
        ]);
    }

    /**
     * The metadata-only view of one AOD/VOD record.
     *
     * @return array<string, mixed>
     */
    private function recordingMeta($record): array
    {
        return [
            'id' => $record->id,
            'original_filename' => $record->original_filename,
            'mime_type' => $record->mime_type,
            'size_bytes' => (int) $record->size_bytes,
        ];
    }

    /**
     * Communication-event counts by type plus the redundant and total tallies.
     *
     * @param  Collection<int, CommEvent>  $events
     * @return array<string, int>
     */
    private function commEventCounts($events): array
    {
        return [
            'informative' => $events->where('communication_type', CommEvent::TYPE_INFORMATIVE)->count(),
            'declarative' => $events->where('communication_type', CommEvent::TYPE_DECLARATIVE)->count(),
            'compound' => $events->where('communication_type', CommEvent::TYPE_COMPOUND)->count(),
            'redundant' => $events->where('is_redundant', true)->count(),
            'total' => $events->count(),
        ];
    }

    /**
     * Communication events per minute over the session window, two decimals.
     */
    private function frequencyPerMin(int $count, int $windowMs): float
    {
        if ($windowMs <= 0) {
            return 0.0;
        }

        return round($count / ($windowMs / 60000), 2);
    }

    /**
     * Provisional team-aggregate talk / silence proxy from communication-event
     * spans: any player talking counts as not silent. Returns
     * [total_talk_ms, total_silence_ms, longest_silence_ms]. Marked provisional
     * pending the real Dead Air milestone (see
     * docs/adr/0006-communication-events.md).
     *
     * @param  Collection<int, CommEvent>  $events
     * @return array{0: int, 1: int, 2: int}
     */
    private function silenceProxy($events, int $windowMs): array
    {
        if ($windowMs <= 0) {
            return [0, 0, 0];
        }

        $intervals = $events
            ->map(fn ($event) => [
                max(0, (int) $event->start_ms),
                min($windowMs, (int) $event->end_ms),
            ])
            ->filter(fn (array $span) => $span[1] > $span[0])
            ->sortBy(0)
            ->values();

        $merged = [];
        foreach ($intervals as [$start, $end]) {
            if ($merged !== [] && $start <= $merged[count($merged) - 1][1]) {
                $merged[count($merged) - 1][1] = max($merged[count($merged) - 1][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        $totalTalk = array_sum(array_map(fn (array $span) => $span[1] - $span[0], $merged));

        $cursor = 0;
        $longestSilence = 0;
        foreach ($merged as [$start, $end]) {
            $longestSilence = max($longestSilence, $start - $cursor);
            $cursor = max($cursor, $end);
        }
        $longestSilence = max($longestSilence, $windowMs - $cursor);

        return [$totalTalk, $windowMs - $totalTalk, $longestSilence];
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

    /**
     * The 409 the read endpoints share: while a session is `processing` the
     * analysis pipeline is mid-run and there is no coherent view to return.
     * Returns the response to send, or null when the session is readable.
     * `timeline_ready` (and later read states) fall through.
     */
    private function refuseWhileProcessing(Session $session): ?JsonResponse
    {
        if ($session->status === Session::STATUS_PROCESSING) {
            return $this->error('This session is still processing.', 409);
        }

        return null;
    }
}
