<?php

namespace App\Models;

use App\Events\SessionParticipantLeft;
use App\Events\SessionParticipantStatusChanged;
use App\Exceptions\SessionTransitionException;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionParticipant extends Model
{
    use HasFactory;

    public const PARTICIPANT_STATUS_NEEDS_CONSENT = 'needs_consent';

    public const PARTICIPANT_STATUS_READY = 'ready';

    public const PARTICIPANT_STATUS_RECORDING = 'recording';

    public const PARTICIPANT_STATUS_COMPLETED = 'completed';

    /**
     * The participant status machine, in strict forward order. A row only ever
     * moves to the next state in this list (or stays put); it never skips ahead
     * or steps back. `recording` and `completed` are driven by the session
     * start sweep and the completion endpoint, not by the participant.
     */
    public const PARTICIPANT_STATUS_SEQUENCE = [
        self::PARTICIPANT_STATUS_NEEDS_CONSENT,
        self::PARTICIPANT_STATUS_READY,
        self::PARTICIPANT_STATUS_RECORDING,
        self::PARTICIPANT_STATUS_COMPLETED,
    ];

    protected $fillable = [
        'session_id',
        'user_id',
        'participant_role',
        'participant_status',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark this participant as having left, then cancel the session if that
     * left it with no active participants at all.
     */
    public function leave(): void
    {
        $this->update(['left_at' => now()]);

        Broadcasting::safely(new SessionParticipantLeft($this));

        $this->session->cancelIfNoParticipantsRemain();
    }

    /**
     * Move this row's participant_status forward to $status. A move to the
     * current status is a silent no-op; anything other than exactly the next
     * state in PARTICIPANT_STATUS_SEQUENCE (skipping ahead, or stepping back)
     * is refused. Callers own the locking; this just enforces the machine.
     *
     * @throws SessionTransitionException when $status is not the current
     *                                    status or the one immediately after it
     */
    public function advanceStatusTo(string $status): void
    {
        $current = $this->participant_status;

        if ($status === $current) {
            return;
        }

        $currentIndex = array_search($current, self::PARTICIPANT_STATUS_SEQUENCE, true);
        $targetIndex = array_search($status, self::PARTICIPANT_STATUS_SEQUENCE, true);

        if ($currentIndex === false || $targetIndex === false || $targetIndex !== $currentIndex + 1) {
            throw new SessionTransitionException("A participant cannot move from {$current} to {$status}.");
        }

        $this->update(['participant_status' => $status]);

        Broadcasting::safely(new SessionParticipantStatusChanged($this));
    }
}
