<?php

namespace App\Models;

use App\Events\SessionParticipantJoined;
use App\Events\SessionParticipantLeft;
use App\Exceptions\SessionTransitionException;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class Session extends Model
{
    use HasFactory;

    /**
     * Named app_sessions, not sessions: the latter is Laravel's own
     * SESSION_DRIVER=database table, unrelated to this domain entity.
     */
    protected $table = 'app_sessions';

    public const STATUS_QUEUING = 'queuing';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const NON_TERMINAL_STATUSES = [self::STATUS_QUEUING, self::STATUS_IN_PROGRESS];

    protected $fillable = [
        'team_id',
        'created_by',
        'session_name',
        'status',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function timeline(): HasOne
    {
        return $this->hasOne(Timeline::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    /**
     * Sessions still in flight (queuing or in_progress) — the single place
     * that defines "does this team have a session blocking a new one."
     */
    public function scopeNonTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', self::NON_TERMINAL_STATUSES);
    }

    /**
     * Participants who haven't left — the roster as it stands right now,
     * which is what API consumers should see by default.
     */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    /**
     * Cancel this session if it's non-terminal and has no active (not-left)
     * participant remaining at all — once everyone who was in it has left,
     * there's no one left to run or take part in it.
     */
    public function cancelIfNoParticipantsRemain(): void
    {
        if (! in_array($this->status, self::NON_TERMINAL_STATUSES, true)) {
            return;
        }

        $hasActiveParticipant = $this->participants()->whereNull('left_at')->exists();

        if (! $hasActiveParticipant) {
            $this->update(['status' => self::STATUS_CANCELLED]);
        }
    }

    /**
     * Create the Session, its Timeline, and the creator's Session Participant
     * row together, atomically. Returns null if the team already has a
     * non-terminal session, without creating anything. The single seam
     * through which a Session can come into existence, so any future caller
     * (a CLI command, a queued job, this controller) inherits the same
     * locking and one-non-terminal-session invariant for free.
     */
    public static function createForTeam(Team $team, User $creator, string $sessionName): ?self
    {
        return DB::transaction(function () use ($team, $creator, $sessionName) {
            // Serializes concurrent creation attempts for this team: the row
            // lock is held until commit, so a second request's exists() check
            // below can't run until this one has either created its session
            // or rolled back — closing the check-then-create race.
            Team::whereKey($team->id)->lockForUpdate()->first();

            if ($team->sessions()->nonTerminal()->exists()) {
                return null;
            }

            $session = self::create([
                'team_id' => $team->id,
                'created_by' => $creator->id,
                'session_name' => $sessionName,
                'status' => self::STATUS_QUEUING,
            ]);

            Timeline::create(['session_id' => $session->id]);

            $session->joinOrRejoin($creator);

            return $session;
        });
    }

    /**
     * Transition this session from queuing to in_progress. The single seam
     * that owns the start guard, so any caller inherits it: a session can
     * only start with at least one player (non-Coach participant) who is
     * currently in it, so we never record an empty room.
     *
     * @throws SessionTransitionException when the guard is not met
     */
    public function start(): void
    {
        DB::transaction(function () {
            // Lock and re-read the row inside the transaction: the guard
            // decides on the session's stored status, not whatever this
            // instance was loaded with, so a concurrent start/cancel can't
            // be clobbered between our check and our write.
            $status = self::whereKey($this->getKey())->lockForUpdate()->value('status');

            if ($status !== self::STATUS_QUEUING) {
                throw new SessionTransitionException('Only a queuing session can be started.');
            }

            $activePlayers = $this->participants()
                ->whereNull('left_at')
                ->whereNotIn('participant_role', User::TEAM_COACH_ROLES)
                ->get();

            if ($activePlayers->isEmpty()) {
                throw new SessionTransitionException('A session needs at least one player before it can start.');
            }

            $allConsented = $activePlayers->every(
                fn (SessionParticipant $player) => $player->participant_status === SessionParticipant::PARTICIPANT_STATUS_READY,
            );

            if (! $allConsented) {
                throw new SessionTransitionException('Every player must consent before the session can start.');
            }

            $this->update(['status' => self::STATUS_IN_PROGRESS]);

            // Only players record; Coach rows stay at `ready` for the run.
            $activePlayers->each(
                fn (SessionParticipant $player) => $player->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_RECORDING),
            );
        });
    }

    /**
     * Transition this session to cancelled from either non-terminal status.
     * Aborting an in_progress run persists nothing: no AOD/VOD record is
     * written until a session reaches completed, so there's nothing here to
     * discard.
     *
     * @throws SessionTransitionException when the session is already terminal
     */
    public function cancel(): void
    {
        DB::transaction(function () {
            $status = self::whereKey($this->getKey())->lockForUpdate()->value('status');

            if (! in_array($status, self::NON_TERMINAL_STATUSES, true)) {
                throw new SessionTransitionException('This session can no longer be cancelled.');
            }

            $this->update(['status' => self::STATUS_CANCELLED]);

            $this->departActiveParticipants();
        });
    }

    /**
     * Record the given user's consent on their own participant row, moving it
     * needs_consent -> ready (idempotent once already ready). The single seam
     * that owns the consent guard: a Coach has nothing to consent to, consent
     * closes once the session is terminal or the row has moved past ready, and
     * only a participant currently in the session can consent at all. Locks
     * and re-reads that row inside the transaction so a concurrent move can't
     * be clobbered between the check and the write.
     *
     * @throws SessionTransitionException when consent is not available to this caller
     */
    public function recordConsent(User $user): SessionParticipant
    {
        return DB::transaction(function () use ($user) {
            $participant = $this->participants()
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->lockForUpdate()
                ->first();

            if (! $participant) {
                throw new SessionTransitionException('You are not in this session.');
            }

            if (in_array($participant->participant_role, User::TEAM_COACH_ROLES, true)) {
                throw new SessionTransitionException('A coach has nothing to consent to.');
            }

            $status = self::whereKey($this->getKey())->value('status');

            $consentOpen = in_array($status, self::NON_TERMINAL_STATUSES, true)
                && in_array($participant->participant_status, [
                    SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT,
                    SessionParticipant::PARTICIPANT_STATUS_READY,
                ], true);

            if (! $consentOpen) {
                throw new SessionTransitionException('Consent can no longer be recorded for this session.');
            }

            $participant->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_READY);

            return $participant;
        });
    }

    /**
     * Mark every still-present participant of this session as having left,
     * broadcasting one departure per row actually touched. Mirrors disband()'s
     * bulk pattern: the UPDATE re-checks left_at IS NULL so a participant who
     * left through a concurrent path keeps their own timestamp and isn't
     * broadcast a second time.
     */
    private function departActiveParticipants(): void
    {
        $leftAt = now();

        $ids = $this->participants()->whereNull('left_at')->pluck('id');

        SessionParticipant::whereIn('id', $ids)
            ->whereNull('left_at')
            ->update(['left_at' => $leftAt]);

        SessionParticipant::whereIn('id', $ids)
            ->where('left_at', $leftAt)
            ->with('user')
            ->get()
            ->each(fn (SessionParticipant $participant) => Broadcasting::safely(new SessionParticipantLeft($participant)));
    }

    /**
     * Add a user as a Session Participant, or reactivate their prior
     * participation if they'd left. The single seam that owns all three
     * states — never joined, previously left, currently active — so callers
     * don't have to lean on Eloquent helper defaults that only cover one of
     * them correctly.
     */
    public function joinOrRejoin(User $user): SessionParticipant
    {
        $role = $user->teamRole($this->team);

        $participant = $this->participants()->where('user_id', $user->id)->first();

        if ($participant) {
            if ($participant->left_at !== null) {
                $participant->update([
                    'left_at' => null,
                    'joined_at' => now(),
                    'participant_role' => $role,
                    'participant_status' => self::initialParticipantStatus($role),
                ]);

                Broadcasting::safely(new SessionParticipantJoined($participant->setRelation('user', $user)));
            }

            return $participant;
        }

        $participant = $this->participants()->create([
            'user_id' => $user->id,
            'participant_role' => $role,
            'participant_status' => self::initialParticipantStatus($role),
            'joined_at' => now(),
        ]);

        Broadcasting::safely(new SessionParticipantJoined($participant->setRelation('user', $user)));

        return $participant;
    }

    /**
     * The participant_status a fresh (or freshly rejoined) row starts at,
     * decided purely from the role snapshot: a Coach has nothing to consent to
     * and is ready immediately; everyone else must consent first.
     */
    private static function initialParticipantStatus(?string $role): string
    {
        return in_array($role, User::TEAM_COACH_ROLES, true)
            ? SessionParticipant::PARTICIPANT_STATUS_READY
            : SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT;
    }
}
