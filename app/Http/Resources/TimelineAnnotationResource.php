<?php

namespace App\Http\Resources;

use App\Models\Annotation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One annotation inline on a timeline entry: the topic, the assessment (null
 * for a narration-only, dead-air or coach row), the body, the game-event ids
 * that drove a system row, the author (null for a system row), and one level of
 * replies (see docs/adr/0008-game-state-alignment.md and
 * docs/adr/0010-timeline-management.md).
 *
 * @mixin Annotation
 */
class TimelineAnnotationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic,
            'assessment' => $this->assessment,
            'body' => $this->body,
            'game_event_ids' => $this->game_event_ids,
            'parent_id' => $this->parent_id,
            'author' => $this->author
                ? ['id' => $this->author->id, 'username' => $this->author->username]
                : null,
            'replies' => $this->whenLoaded(
                'replies',
                fn () => $this->replies
                    ->map(fn (Annotation $reply) => (new self($reply))->toArray(request()))
                    ->values()
                    ->all(),
                [],
            ),
        ];
    }

    /**
     * Nest a flat annotation collection into the timeline shape: top-level rows
     * first (`parent_id` null), each carrying its reply children one level deep.
     * Pass a timestamp's loaded `annotations` relation.
     *
     * @param  iterable<Annotation>  $annotations
     * @return list<array<string, mixed>>
     */
    public static function tree(iterable $annotations): array
    {
        $byParent = (new Collection($annotations))->groupBy(
            fn (Annotation $a) => $a->parent_id ?? 'root',
        );

        return $byParent->get('root', new Collection)
            ->map(function (Annotation $note) use ($byParent): array {
                $note->setRelation('replies', $byParent->get($note->id, new Collection));

                return (new self($note))->toArray(request());
            })
            ->values()
            ->all();
    }
}
