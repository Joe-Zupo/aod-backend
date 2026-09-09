<?php

namespace App\Support;

use App\Models\CommEvent;
use App\Models\DeadAirPeriod;
use App\Models\GameEvent;
use App\Models\Session;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the polymorphic `{type}` segment on the timeline-management routes to
 * its model, and asserts a resolved row belongs to the route's session (see
 * docs/adr/0010-timeline-management.md). The tokens match the `type` field the
 * timeline payload already emits.
 */
class TimelineTimestamp
{
    /**
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        'communication_event' => CommEvent::class,
        'game_event' => GameEvent::class,
        'dead_air' => DeadAirPeriod::class,
    ];

    /**
     * The `{type}` tokens a coach may create. Game events come only from the
     * match feed.
     */
    public const CREATABLE_TYPES = ['communication_event', 'dead_air'];

    /**
     * @return class-string<Model>
     */
    public static function modelClass(string $type): string
    {
        return self::TYPES[$type] ?? abort(404, 'Unknown timestamp type.');
    }

    public static function resolve(Session $session, string $type, int|string $id): Model
    {
        $model = self::modelClass($type)::query()->find($id) ?? abort(404);

        abort_unless(self::belongsToSession($model, $session), 404);

        return $model;
    }

    private static function belongsToSession(Model $model, Session $session): bool
    {
        if ($model instanceof CommEvent) {
            return $session->commEventsQuery()->whereKey($model->getKey())->exists();
        }

        return (int) $model->session_id === (int) $session->getKey();
    }
}
