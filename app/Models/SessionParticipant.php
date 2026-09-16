<?php

namespace App\Models;

use App\Events\SessionParticipantLeft;
use App\Events\SessionParticipantStatusChanged;
use App\Exceptions\SessionTransitionException;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

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

    public function aodRecord(): HasOne
    {
        return $this->hasOne(AodRecord::class);
    }

    public function vodRecord(): HasOne
    {
        return $this->hasOne(VodRecord::class);
    }

    /**
     * Mark this participant as having left, discard whatever they had already
     * uploaded, and apply the two rules that hang off a departure, in this
     * order: cancel the session if nobody active is left in it at all, and
     * otherwise return it to `queuing` if that was the last participant
     * recording.
     *
     * The row drops to the status its role starts at. That is what makes the
     * departure total: `uploadRecording()` and `complete()` both key off
     * `participant_status === recording`, so a departed row left at `recording`
     * would still have its files transcribed after the player walked away
     * (docs/adr/0013-recording-control-and-departure.md).
     *
     * The rules live here rather than in the leave endpoint so every caller
     * inherits them — the endpoint, logout, team member removal — because a
     * Coach who closes the app has left exactly as much as one who clicked
     * leave. Everything runs under a lock on the session row, taken before this
     * row is re-read: both rules decide on a count of who is still recording,
     * so two participants leaving at once would otherwise each see the other.
     *
     * @return array{audio: bool, video: bool} which recordings were discarded
     */
    public function leave(): array
    {
        $discarded = ['audio' => false, 'video' => false];
        $session = null;

        DB::transaction(function () use (&$discarded, &$session) {
            $session = Session::whereKey($this->session_id)->lockForUpdate()->first();

            // Departing a row that already has `left_at` is a no-op: gone is the
            // state the caller asked for, and re-stamping would move a timestamp
            // other clients already hold.
            if ($this->newQuery()->whereKey($this->getKey())->whereNotNull('left_at')->exists()) {
                return;
            }

            $discarded = $session->discardRecordingsForParticipant($this);

            $this->update([
                'left_at' => now(),
                'participant_status' => Session::initialParticipantStatus($this->participant_role),
            ]);

            Broadcasting::safely(new SessionParticipantLeft($this));

            if ($session->cancelIfNoParticipantsRemain()) {
                return;
            }

            $session->regressIfNobodyRecording();
        });

        $session?->flushDiscardedRecordings();

        return $discarded;
    }

    /**
     * Send this row back to the status its role starts at, clearing any capture
     * segment with it. The one backward move in the machine, and the second
     * place that bypasses advanceStatusTo() after joinOrRejoin(): a session that
     * returns to `queuing` starts a new run, and ADR 0002 makes consent per-run,
     * so every player must consent again
     * (docs/adr/0013-recording-control-and-departure.md).
     */
    public function resetStatus(string $status): void
    {
        $this->update(['participant_status' => $status]);

        Broadcasting::safely(new SessionParticipantStatusChanged($this));
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
