<?php

namespace Database\Factories;

use App\Models\SessionParticipant;
use App\Models\VodRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VodRecord>
 */
class VodRecordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_participant_id' => SessionParticipant::factory(),
            'disk' => 'local',
            'path' => 'session-recordings/1/1/vod.mp4',
            'original_filename' => 'vod.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 98_304,
        ];
    }
}
