<?php

namespace Database\Factories;

use App\Models\AodRecord;
use App\Models\SessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AodRecord>
 */
class AodRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_participant_id' => SessionParticipant::factory(),
            'disk' => 'local',
            'path' => 'session-recordings/1/1/aod.mp3',
            'original_filename' => 'aod.mp3',
            'mime_type' => 'audio/mpeg',
            'size_bytes' => 16_384,
        ];
    }
}
