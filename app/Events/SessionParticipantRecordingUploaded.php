<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsSessionParticipant;
use App\Models\SessionParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A participant stored a recording for the current run, so every client's view
 * of who has delivered updates without polling
 * (docs/adr/0015-end-of-run-and-end-of-participation.md).
 */
class SessionParticipantRecordingUploaded implements ShouldBroadcastNow
{
    use BroadcastsSessionParticipant, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SessionParticipant $participant) {}
}
