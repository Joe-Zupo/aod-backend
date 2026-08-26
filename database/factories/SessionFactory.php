<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'session_name' => fake()->words(3, true),
            'status' => Session::STATUS_QUEUING,
        ];
    }

    public function queuing(): static
    {
        return $this->state(['status' => Session::STATUS_QUEUING]);
    }

    public function inProgress(): static
    {
        return $this->state(['status' => Session::STATUS_IN_PROGRESS]);
    }

    public function completed(): static
    {
        return $this->state(['status' => Session::STATUS_COMPLETED]);
    }

    public function cancelled(): static
    {
        return $this->state(['status' => Session::STATUS_CANCELLED]);
    }
}
