<?php

namespace App\Http\Controllers;

use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use App\Support\TimelineTimestamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Coach review actions over the three timestamp kinds while a session is
 * `timeline_ready` or `analysis_ready` (see
 * docs/adr/0010-timeline-management.md). CRUD of timestamps lives here too.
 */
class TimestampController extends Controller
{
    /**
     * Set Timestamp Review
     *
     * `{ "reviewed": true }` signs the timestamp off (stamped to the acting
     * Coach); `{ "reviewed": false }` clears the sign-off. Returns the session's
     * running `{ reviewed, total }`.
     */
    public function review(Request $request, Session $session, string $type, string $id): JsonResponse
    {
        $this->authorize('manageTimeline', $session);

        $reviewed = $request->validate(['reviewed' => ['required', 'boolean']])['reviewed'];
        $timestamp = TimelineTimestamp::resolve($session, $type, $id);

        $reviewed
            ? $timestamp->markReviewed($request->user())
            : $timestamp->clearReview();

        return $this->success('Timestamp review set.', $session->reviewCounts());
    }

    /**
     * Mark Every Timestamp Reviewed
     *
     * Stamp every currently-unreviewed timestamp in the session to the acting
     * Coach. Idempotent.
     */
    public function reviewAll(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manageTimeline', $session);

        $stamp = ['reviewed_at' => now(), 'reviewed_by' => $request->user()->getKey()];

        $session->commEventsQuery()->unreviewed()->update($stamp);
        $session->gameEvents()->unreviewed()->update($stamp);
        $session->deadAirPeriods()->unreviewed()->update($stamp);

        return $this->success('All timestamps marked reviewed.', $session->reviewCounts());
    }

    /**
     * Create Timestamp
     *
     * A coach adds a communication event (to one participant) or a dead-air
     * period the detector missed. Game events are feed-only. The new row starts
     * reviewed, stamped to its author.
     */
    public function store(Request $request, Session $session): JsonResponse
    {
        $this->authorize('manageTimeline', $session);

        $type = $request->input('type');

        abort_unless(
            in_array($type, TimelineTimestamp::CREATABLE_TYPES, true),
            422,
            'A coach may only create a communication_event or a dead_air timestamp.',
        );

        $coach = $request->user();
        $stamp = ['created_by' => $coach->getKey(), 'reviewed_at' => now(), 'reviewed_by' => $coach->getKey()];

        if ($type === 'communication_event') {
            $data = $request->validate([
                'participant_id' => ['required', 'integer'],
                'communication_type' => ['required', Rule::in(CommEvent::TYPES)],
                'content' => ['required', 'string', 'max:2000'],
                'start_ms' => ['required', 'integer', 'min:0'],
                'end_ms' => ['required', 'integer', 'gte:start_ms'],
            ]);

            $transcript = $session->participants()
                ->whereKey($data['participant_id'])
                ->first()?->aodRecord?->transcript;

            if (! $transcript) {
                throw ValidationException::withMessages(['participant_id' => 'That participant has no transcript on this session.']);
            }

            $this->assertSpanInWindow($session, $data['end_ms']);

            $row = CommEvent::create([
                'transcript_id' => $transcript->id,
                'communication_type' => $data['communication_type'],
                'is_redundant' => false,
                'start_ms' => $data['start_ms'],
                'end_ms' => $data['end_ms'],
                'content' => $data['content'],
                'padding_ms' => null,
                ...$stamp,
            ]);
        } else {
            $data = $request->validate([
                'start_ms' => ['required', 'integer', 'min:0'],
                'end_ms' => ['required', 'integer', 'gte:start_ms'],
            ]);

            $this->assertSpanInWindow($session, $data['end_ms']);

            $row = DeadAirPeriod::create([
                'session_id' => $session->getKey(),
                'start_ms' => $data['start_ms'],
                'end_ms' => $data['end_ms'],
                'dead_air_threshold_ms' => null,
                ...$stamp,
            ]);
        }

        return $this->success('Timestamp created.', ['id' => $row->id, 'type' => $type], 201);
    }

    /**
     * Edit Timestamp
     *
     * Partial update against a per-kind whitelist. Any change clears the row's
     * review stamp.
     */
    public function update(Request $request, Session $session, string $type, string $id): JsonResponse
    {
        $this->authorize('manageTimeline', $session);

        $row = TimelineTimestamp::resolve($session, $type, $id);
        $data = $this->validateEdit($request, $session, $row);

        if ($data !== []) {
            $row->fill($data)->save();
            $row->clearReview();
        }

        return $this->success('Timestamp updated.');
    }

    /**
     * Delete Timestamp
     *
     * Removes the row and its whole annotation subtree (system rows, coach
     * notes, replies). A game event carries no annotations.
     */
    public function destroy(Request $request, Session $session, string $type, string $id): JsonResponse
    {
        $this->authorize('manageTimeline', $session);

        $row = TimelineTimestamp::resolve($session, $type, $id);

        // The polymorphic annotatable has no database cascade, so the whole
        // annotation subtree (system rows, coach notes, replies) goes here.
        $row->annotations()->delete();
        $row->delete();

        return $this->success('Timestamp deleted.');
    }

    private function assertSpanInWindow(Session $session, int $endMs): void
    {
        $window = $session->windowMs();

        if ($window > 0 && $endMs > $window) {
            throw ValidationException::withMessages(['end_ms' => 'A timestamp cannot run past the session window.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEdit(Request $request, Session $session, object $row): array
    {
        $rules = match (true) {
            $row instanceof GameEvent => [
                'type' => ['sometimes', Rule::in(GameEvent::TYPES)],
                'side' => ['sometimes', 'nullable', Rule::in(['ally', 'enemy'])],
                'match_time_ms' => ['sometimes', 'integer', 'min:0'],
                'round_number' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            $row instanceof CommEvent => [
                'start_ms' => ['sometimes', 'integer', 'min:0'],
                'end_ms' => ['sometimes', 'integer'],
                'content' => ['sometimes', 'string', 'max:2000'],
                'communication_type' => ['sometimes', Rule::in(CommEvent::TYPES)],
                'is_redundant' => ['sometimes', 'boolean'],
            ],
            default => [
                'start_ms' => ['sometimes', 'integer', 'min:0'],
                'end_ms' => ['sometimes', 'integer'],
            ],
        };

        $data = $request->validate($rules);

        $start = $data['start_ms'] ?? $row->start_ms ?? null;
        $end = $data['end_ms'] ?? $row->end_ms ?? null;

        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['end_ms' => 'end_ms must be at least start_ms.']);
        }

        if ($end !== null) {
            $this->assertSpanInWindow($session, (int) $end);
        }

        if ($row instanceof GameEvent) {
            $type = $data['type'] ?? $row->type;
            $side = array_key_exists('side', $data) ? $data['side'] : $row->side;
            $isRound = in_array($type, GameEvent::ROUND_TYPES, true);

            if ($isRound && $side !== null) {
                throw ValidationException::withMessages(['side' => 'A round outcome carries no side.']);
            }

            if (! $isRound && $side === null) {
                throw ValidationException::withMessages(['side' => 'This game-event type needs a side.']);
            }
        }

        return $data;
    }
}
