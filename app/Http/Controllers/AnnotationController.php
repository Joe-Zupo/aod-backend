<?php

namespace App\Http\Controllers;

use App\Models\Annotation;
use App\Models\Session;
use App\Support\TimelineTimestamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notes on timestamps, and replies (one level) to any annotation including the
 * system's (see docs/adr/0010-timeline-management.md).
 */
class AnnotationController extends Controller
{
    /**
     * Create Annotation
     *
     * A free-text `note` on one timestamp. `{type}` is
     * `communication_event` | `game_event` | `dead_air`; `{id}` is the row id
     * within that kind. Authored by the caller: a coach while the session is
     * `timeline_ready` or `analysis_ready`, a player only once `analysis_ready`.
     */
    public function store(Request $request, Session $session, string $type, string $id): JsonResponse
    {
        $this->authorize('annotateTimeline', $session);

        $timestamp = TimelineTimestamp::resolve($session, $type, $id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $note = $timestamp->annotations()->create([
            'parent_id' => null,
            'user_id' => $request->user()->getKey(),
            'topic' => Annotation::TOPIC_NOTE,
            'assessment' => null,
            'body' => $data['body'],
            'game_event_ids' => [],
            'alignment_window_ms' => null,
        ]);

        return $this->success('Annotation created.', ['id' => $note->id], 201);
    }

    /**
     * Edit Annotation
     *
     * The author edits their own note or reply. A system annotation is
     * immutable.
     */
    public function update(Request $request, Annotation $annotation): JsonResponse
    {
        $this->assertAuthoredBy($annotation, $request);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $annotation->update(['body' => $data['body']]);

        return $this->success('Annotation updated.');
    }

    /**
     * Delete Annotation
     *
     * The author deletes their own note or reply; a top-level note takes its
     * replies with it. A system annotation is immutable.
     */
    public function destroy(Request $request, Annotation $annotation): JsonResponse
    {
        $this->assertAuthoredBy($annotation, $request);

        $annotation->delete();

        return $this->success('Annotation deleted.');
    }

    /**
     * Reply to Annotation
     *
     * One level only: the parent must be a top-level annotation. Coaches reply
     * while under review or analysis-ready; players only once analysis-ready.
     */
    public function reply(Request $request, Annotation $annotation): JsonResponse
    {
        $session = $annotation->timelineSession() ?? abort(404);

        $this->authorize('annotateTimeline', $session);

        abort_if($annotation->isReply(), 422, 'You can only reply to a top-level annotation.');

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $reply = $annotation->replies()->create([
            'annotatable_type' => $annotation->annotatable_type,
            'annotatable_id' => $annotation->annotatable_id,
            'user_id' => $request->user()->getKey(),
            'topic' => Annotation::TOPIC_REPLY,
            'assessment' => null,
            'body' => $data['body'],
            'game_event_ids' => [],
            'alignment_window_ms' => null,
        ]);

        return $this->success('Reply added.', ['id' => $reply->id], 201);
    }

    private function assertAuthoredBy(Annotation $annotation, Request $request): void
    {
        abort_if($annotation->isSystem(), 403, 'A system annotation cannot be edited.');
        abort_unless($annotation->user_id === $request->user()->getKey(), 403);
    }
}
