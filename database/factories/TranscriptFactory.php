<?php

namespace Database\Factories;

use App\Models\AodRecord;
use App\Models\Transcript;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transcript>
 */
class TranscriptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'aod_record_id' => AodRecord::factory(),
            'provider' => Transcript::PROVIDER_ASSEMBLYAI,
            'provider_transcript_id' => null,
            'status' => Transcript::STATUS_QUEUED,
            'poll_count' => 0,
        ];
    }

    public function processing(string $providerTranscriptId = 'prov_123'): static
    {
        return $this->state([
            'status' => Transcript::STATUS_PROCESSING,
            'provider_transcript_id' => $providerTranscriptId,
        ]);
    }

    public function failed(string $error = 'something went wrong'): static
    {
        return $this->state([
            'status' => Transcript::STATUS_FAILED,
            'error' => $error,
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => Transcript::STATUS_COMPLETED,
            'provider_transcript_id' => 'prov_done',
            'text' => 'two mid',
            'language_code' => 'en',
        ]);
    }
}
