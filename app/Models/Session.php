<?php

namespace App\Models;

use App\Events\SessionParticipantJoined;
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
     * @throws \DomainException when the guard is not met
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
                throw new \DomainException('Only a queuing session can be started.');
            }

            $hasActivePlayer = $this->participants()
                ->whereNull('left_at')
                ->whereNotIn('participant_role', User::TEAM_COACH_ROLES)
                ->exists();

            if (! $hasActivePlayer) {
                throw new \DomainException('A session needs at least one player before it can start.');
            }

            $this->update(['status' => self::STATUS_IN_PROGRESS]);
        });
    }

    /**
     * Transition this session to cancelled from either non-terminal status.
     * Aborting an in_progress run persists nothing: no AOD/VOD record is
     * written until a session reaches completed, so there's nothing here to
     * discard.
     *
     * @throws \DomainException when the session is already terminal
     */
    public function cancel(): void
    {
        DB::transaction(function () {
            $status = self::whereKey($this->getKey())->lockForUpdate()->value('status');

            if (! in_array($status, self::NON_TERMINAL_STATUSES, true)) {
                throw new \DomainException('This session can no longer be cancelled.');
            }

            $this->update(['status' => self::STATUS_CANCELLED]);
        });
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
        $participant = $this->participants()->where('user_id', $user->id)->first();

        if ($participant) {
            if ($participant->left_at !== null) {
                $participant->update([
                    'left_at' => null,
                    'joined_at' => now(),
                    'participant_role' => $user->teamRole($this->team),
                ]);

                Broadcasting::safely(new SessionParticipantJoined($participant->setRelation('user', $user)));
            }

            return $participant;
        }

        $participant = $this->participants()->create([
            'user_id' => $user->id,
            'participant_role' => $user->teamRole($this->team),
            'joined_at' => now(),
        ]);

        Broadcasting::safely(new SessionParticipantJoined($participant->setRelation('user', $user)));

        return $participant;
    }
}
