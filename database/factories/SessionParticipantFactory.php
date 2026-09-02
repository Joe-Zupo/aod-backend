<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionParticipant>
 */
class SessionParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => Session::factory(),
            'user_id' => User::factory(),
            'participant_role' => 'player',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_READY,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(['left_at' => now()]);
    }

    public function needsConsent(): static
    {
        return $this->state(['participant_status' => SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT]);
    }
}
