<?php

namespace Database\Factories;

use App\Models\DeadAirPeriod;
use App\Models\Session;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeadAirPeriod>
 */
class DeadAirPeriodFactory extends Factory
{
    /**
     * A single 8-second silent stretch under the default threshold. Callers
     * pass the session with ->for($session) and override the span.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->numberBetween(0, 60000);

        return [
            'session_id' => Session::factory(),
            'start_ms' => $start,
            'end_ms' => $start + 8000,
            'dead_air_threshold_ms' => 5000,
        ];
    }
}
