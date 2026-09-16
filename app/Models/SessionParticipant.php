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
     * The relations a participant's representation reads, in the API resource
     * and in every participant broadcast: the user, and the Delivery State.
     */
    public const REPRESENTATION_RELATIONS = ['user', 'aodRecord', 'vodRecord'];

    /**
     * The participant status machine, as the moves each status allows. A row
     * only ever takes one of these edges or stays put; it never steps back and
     * never skips a status it was meant to pass through.
     *
     * Every status reaches `completed`, because it means "this session is over
     * for me" rather than "I finished recording": a Coach never records, a
     * player who stopped early is back at `needs_consent`, and the session ends
     * for both of them (docs/adr/0014-participation-lifecycle.md). Reaching
     * `completed` from `needs_consent` is not a consent loophole — it ends
     * participation rather than granting it, and `start()` still requires
     * `ready`. Nothing follows `completed`.
     *
     * `recording` and `completed` are driven by the session start sweep and the
     * completion endpoint, not by the participant.
     *
     * @var array<string, list<string>>
     */
    public const PARTICIPANT_STATUS_TRANSITIONS = [
        self::PARTICIPANT_STATUS_NEEDS_CONSENT => [self::PARTICIPANT_STATUS_READY, self::PARTICIPANT_STATUS_COMPLETED],
        self::PARTICIPANT_STATUS_READY => [self::PARTICIPANT_STATUS_RECORDING, self::PARTICIPANT_STATUS_COMPLETED],
        self::PARTICIPANT_STATUS_RECORDING => [self::PARTICIPANT_STATUS_COMPLETED],
        self::PARTICIPANT_STATUS_COMPLETED => [],
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
     * Begin recording, moving `ready` -> `recording`. Already recording is a
     * silent no-op; anything earlier in the sequence is refused by
     * advanceStatusTo(), which is what stops a player who has not consented
     * from recording (docs/adr/0013-recording-control-and-departure.md).
     *
     * @throws SessionTransitionException when this row has not consented yet
     */
    public function startRecording(): void
    {
        if ($this->participant_status === self::PARTICIPANT_STATUS_RECORDING) {
            return;
        }

        if ($this->participant_status !== self::PARTICIPANT_STATUS_READY) {
            throw new SessionTransitionException('Consent to this session before recording.');
        }

        $this->advanceStatusTo(self::PARTICIPANT_STATUS_RECORDING);
    }

    /**
     * Stop recording, dropping back to `needs_consent`. Stopping is leaving the
     * run rather than pausing it: the player is off the completion roster and
     * must consent again before recording again. A row that is not recording is
     * already in the state the caller asked for, so it is a no-op.
     */
    public function stopRecording(): void
    {
        if ($this->participant_status !== self::PARTICIPANT_STATUS_RECORDING) {
            return;
        }

        $this->resetStatus(self::PARTICIPANT_STATUS_NEEDS_CONSENT);
    }

    /**
     * Move this row's participant_status forward to $status. A move to the
     * current status is a silent no-op; anything PARTICIPANT_STATUS_TRANSITIONS
     * does not allow from the current status is refused. Callers own the
     * locking; this just enforces the machine.
     *
     * @throws SessionTransitionException when the move is not an allowed edge
     */
    public function advanceStatusTo(string $status): void
    {
        $current = $this->participant_status;

        if ($status === $current) {
            return;
        }

        $allowed = self::PARTICIPANT_STATUS_TRANSITIONS[$current] ?? [];

        if (! in_array($status, $allowed, true)) {
            throw new SessionTransitionException("A participant cannot move from {$current} to {$status}.");
        }

        $this->update(['participant_status' => $status]);

        Broadcasting::safely(new SessionParticipantStatusChanged($this));
    }
}
