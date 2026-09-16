<?php

namespace Tests\Unit\Models;

use App\Models\AodRecord;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\Team;
use App\Models\User;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `cancelIfNoParticipantsRemain()` is the fourth path to `cancelled`, beside
 * `cancel()`. ADR 0012 makes "cancelling discards everything" an invariant, and
 * this pins that this path honours it too.
 *
 * Every HTTP route to an empty session runs through `SessionParticipant::leave()`,
 * which discards on the way out, so a stored recording cannot survive to reach
 * this guard today. That is an accident of the current call graph rather than
 * something the code states, so the state is built directly here.
 */
class SessionCancelOnEmptyTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelling_an_emptied_session_discards_any_recording_left_behind(): void
    {
        Storage::fake('local');

        $team = Team::factory()->create();
        $user = User::factory()->create();
        $session = Session::factory()->for($team)->create([
            'created_by' => $user->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);

        // A departed row that still holds recordings: not reachable through the
        // endpoints, but the guard must not depend on that staying true.
        $participant = SessionParticipant::factory()->for($session)->create([
            'user_id' => $user->id,
            'participant_role' => 'player',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING,
            'left_at' => now(),
        ]);

        Storage::disk('local')->put('recordings/orphan.m4a', 'audio');
        Storage::disk('local')->put('recordings/orphan.mp4', 'video');

        AodRecord::create([
            'session_participant_id' => $participant->id,
            'disk' => 'local',
            'path' => 'recordings/orphan.m4a',
            'original_filename' => 'orphan.m4a',
            'mime_type' => 'audio/mp4',
            'size_bytes' => 5,
        ]);
        VodRecord::create([
            'session_participant_id' => $participant->id,
            'disk' => 'local',
            'path' => 'recordings/orphan.mp4',
            'original_filename' => 'orphan.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 5,
        ]);

        $this->assertTrue($session->cancelIfNoParticipantsRemain());

        // The guard deletes rows inside its caller's transaction; files are
        // unlinked once that has committed.
        $session->flushDiscardedRecordings();

        $this->assertSame(Session::STATUS_CANCELLED, $session->fresh()->status);
        $this->assertDatabaseMissing('aod_records', ['session_participant_id' => $participant->id]);
        $this->assertDatabaseMissing('vod_records', ['session_participant_id' => $participant->id]);
        Storage::disk('local')->assertMissing('recordings/orphan.m4a');
        Storage::disk('local')->assertMissing('recordings/orphan.mp4');
    }

    public function test_a_session_that_still_has_someone_in_it_is_left_alone(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $session = Session::factory()->for($team)->create([
            'created_by' => $user->id,
            'status' => Session::STATUS_IN_PROGRESS,
        ]);
        SessionParticipant::factory()->for($session)->create([
            'user_id' => $user->id,
            'participant_role' => 'player',
            'participant_status' => SessionParticipant::PARTICIPANT_STATUS_RECORDING,
        ]);

        $this->assertFalse($session->cancelIfNoParticipantsRemain());
        $this->assertSame(Session::STATUS_IN_PROGRESS, $session->fresh()->status);
    }
}
