<?php

namespace Database\Factories;

use App\Models\Annotation;
use App\Models\CommEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Annotation>
 */
class AnnotationFactory extends Factory
{
    /**
     * A system game-state-alignment row on a communication event. Callers pass
     * the target with ->for($commEvent, 'annotatable').
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'annotatable_type' => (new CommEvent)->getMorphClass(),
            'annotatable_id' => 1,
            'user_id' => null,
            'topic' => Annotation::TOPIC_GAME_STATE_ALIGNMENT,
            'assessment' => Annotation::ASSESSMENT_POSSIBLY_POSITIVE,
            'body' => 'Callout mapped to spike_plant; ally spike_plant 900 ms later.',
            'game_event_ids' => [],
            'alignment_window_ms' => 5000,
        ];
    }

    public function deadAir(): static
    {
        return $this->state(fn () => [
            'topic' => Annotation::TOPIC_DEAD_AIR,
            'assessment' => null,
            'body' => '6.2s of team silence; enemy spike_plant 1.1s in went uncalled. '.'Consider reviewing this moment.',
            'alignment_window_ms' => null,
        ]);
    }
}
