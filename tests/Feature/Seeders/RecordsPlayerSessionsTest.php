<?php

namespace Tests\Feature\Seeders;

use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Transcript;
use Database\Seeders\Concerns\RecordsPlayerSessions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordsPlayerSessionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_a_completed_aod_vod_and_transcript_to_a_participant(): void
    {
        $session = Session::factory()->create();
        $participant = SessionParticipant::factory()->for($session)->create();

        $harness = new class
        {
            use RecordsPlayerSessions;

            public function call(SessionParticipant $participant): Transcript
            {
                return $this->recordCompletedTranscript($participant);
            }
        };

        $transcript = $harness->call($participant);

        $participant->refresh();

        $this->assertNotNull($participant->aodRecord);
        $this->assertNotNull($participant->vodRecord);
        $this->assertSame($participant->aodRecord->id, $transcript->aod_record_id);
        $this->assertSame(Transcript::STATUS_COMPLETED, $transcript->status);
    }
}
