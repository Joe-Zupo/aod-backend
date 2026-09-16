<?php

namespace App\Events\Concerns;

use App\Http\Resources\SessionParticipantResource;
use App\Models\SessionParticipant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * The one channel and payload every participant event shares: the
 * participant's session channel, and the participant as
 * `GET /sessions/{session}` shows them, Delivery State included. A client can
 * replace its row wholesale on any participant event without knowing which
 * moves discard recordings (docs/adr/0015-end-of-run-and-end-of-participation.md).
 *
 * @property SessionParticipant $participant
 */
trait BroadcastsSessionParticipant
{
    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('session.'.$this->participant->session_id)];
    }

    /**
     * Recordings are reloaded rather than trusted: a broadcast fires after
     * commit, and a discard earlier in the same transaction may have deleted
     * rows this instance still holds.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->participant->loadMissing('user')->load(['aodRecord', 'vodRecord']);

        return [
            'session_id' => $this->participant->session_id,
            ...(new SessionParticipantResource($this->participant))->resolve(),
        ];
    }
}
