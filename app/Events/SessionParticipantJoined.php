<?php

namespace App\Events;

use App\Models\SessionParticipant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionParticipantJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public SessionParticipant $participant) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('session.'.$this->participant->session_id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->participant->session_id,
            'user_id' => $this->participant->user_id,
            'username' => $this->participant->user->username,
            'participant_role' => $this->participant->participant_role,
            'participant_status' => $this->participant->participant_status,
            'joined_at' => $this->participant->joined_at,
            'left_at' => $this->participant->left_at,
        ];
    }
}
