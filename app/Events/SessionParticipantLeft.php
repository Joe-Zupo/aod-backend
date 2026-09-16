<?php

namespace App\Events;

use App\Events\Concerns\BroadcastsSessionParticipant;
use App\Models\SessionParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionParticipantLeft implements ShouldBroadcastNow
{
    use BroadcastsSessionParticipant, Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SessionParticipant $participant) {}
}
