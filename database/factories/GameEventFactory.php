<?php

namespace Database\Factories;

use App\Models\GameEvent;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GameEvent>
 */
class GameEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => Session::factory(),
            'source' => 'manual',
            'type' => 'kill',
            'side' => 'ally',
            'match_time_ms' => $this->faker->numberBetween(0, 3_599_000),
            'round_number' => $this->faker->numberBetween(1, 24),
            'note' => null,
            'raw' => null,
        ];
    }

    public function roundWin(): static
    {
        return $this->state(['type' => 'round_win', 'side' => null]);
    }

    public function roundLost(): static
    {
        return $this->state(['type' => 'round_lost', 'side' => null]);
    }
}
