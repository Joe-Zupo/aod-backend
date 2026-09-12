<?php

namespace Database\Seeders\Concerns;

use App\Models\AodRecord;
use App\Models\SessionParticipant;
use App\Models\Transcript;
use App\Models\VodRecord;

/**
 * Attaches a completed AOD+VOD recording pair and a completed Transcript to an
 * existing session participant. The shared primitive behind both
 * `Tests\Concerns\BuildsTimeline` and the demo seeders, extracted so it lives
 * on the main autoload (`tests/` is dev-only, see composer.json) and neither
 * side duplicates the other's factory wiring.
 */
trait RecordsPlayerSessions
{
    protected function recordCompletedTranscript(SessionParticipant $participant, int $audioDurationMs = 300000): Transcript
    {
        $aod = AodRecord::factory()->for($participant)->create();
        VodRecord::factory()->for($participant)->create();

        return Transcript::factory()->for($aod)->completed()->create([
            'audio_duration_ms' => $audioDurationMs,
            'comm_events_detected' => true,
        ]);
    }
}
