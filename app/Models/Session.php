<?php

namespace App\Models;

use App\Events\SessionParticipantJoined;
use App\Events\SessionParticipantLeft;
use App\Events\SessionStatusChanged;
use App\Exceptions\SessionTransitionException;
use App\Jobs\DetectCommEvents;
use App\Jobs\SubmitTranscription;
use App\Support\Broadcasting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class Session extends Model
{
    use HasFactory;

    /**
     * Named app_sessions, not sessions: the latter is Laravel's own
     * SESSION_DRIVER=database table, unrelated to this domain entity.
     */
    protected $table = 'app_sessions';

    /**
     * The disk AOD/VOD files are stored on. Each record row also stores its own
     * disk name, so moving to another disk later is a data migration rather
     * than a schema change (see docs/adr/0003-session-recording-storage.md).
     */
    public const RECORDING_DISK = 'local';

    public const STATUS_QUEUING = 'queuing';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PROCESSING = 'processing';

    /**
     * The analysis pipeline has produced captions and communication-event
     * timestamps under the zero-offset assumption. The read surface opens here:
     * `GET /sessions/{id}` succeeds again and the timeline, timeline-summary and
     * captions endpoints serve the session (see
     * docs/adr/0006-communication-events.md).
     */
    public const STATUS_TIMELINE_READY = 'timeline_ready';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The two statuses that count as a live session for the team: they block a
     * second session, drive the index's live_session slot, and keep the
     * auto-cancel and consent windows open. `processing` is deliberately not
     * among them. Once the coach has completed the session its recording is
     * done and stored, so the team is free to start the next scrim while
     * analysis runs in the background.
     */
    public const NON_TERMINAL_STATUSES = [self::STATUS_QUEUING, self::STATUS_IN_PROGRESS];

    /**
     * Prefix of every Session's human-facing code. The full code is this plus
     * the id, zero-padded to at least three digits (SESSION_001, SESSION_048).
     */
    public const CODE_PREFIX = 'SESSION_';

    protected $fillable = [
        'team_id',
        'created_by',
        'session_name',
        'session_code',
        'status',
        'game_alignment_assessed_at',
        'dead_air_detected_at',
    ];

    protected $casts = [
        'game_alignment_assessed_at' => 'datetime',
        'dead_air_detected_at' => 'datetime',
    ];

    /**
     * The code for a given Session id: CODE_PREFIX plus the id, zero-padded to
     * at least three digits.
     */
    public static function codeForId(int $id): string
    {
        return self::CODE_PREFIX.str_pad((string) $id, 3, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        // session_code embeds the id, which only exists after insert, so it is
        // written in a second (quiet) save here rather than in the create()
        // attributes. Every creation path (createForTeam, factories) runs this.
        static::created(function (self $session): void {
            if ($session->session_code === null) {
                $session->session_code = self::codeForId($session->id);
                $session->saveQuietly();
            }
        });
    }

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

    public function gameEvents(): HasMany
    {
        return $this->hasMany(GameEvent::class)->orderBy('match_time_ms');
    }

    /**
     * The session's team-aggregate silent stretches, written by DetectDeadAir,
     * ordered like the timeline reads them (see
     * docs/adr/0009-dead-air-detection.md).
     */
    public function deadAirPeriods(): HasMany
    {
        return $this->hasMany(DeadAirPeriod::class)->orderBy('start_ms');
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
     * A query for every transcript belonging to this session, reached through
     * its participants' AOD records. Session -> AodRecord is itself a hop
     * through SessionParticipant, so this is a plain subquery, not an Eloquent
     * relation, and is named accordingly.
     */
    public function transcriptsQuery(): Builder
    {
        return Transcript::query()->whereIn(
            'aod_record_id',
            AodRecord::query()
                ->select('id')
                ->whereIn('session_participant_id', $this->participants()->select('id')),
        );
    }

    /**
     * How far this session's per-AOD transcription has got, as one aggregate
     * over the transcript rows: total, and how many have reached `completed`
     * or `failed`. This is the only progress the `processing` phase exposes;
     * the session itself carries no per-transcript state.
     *
     * @return array{total: int, completed: int, failed: int}
     */
    public function transcriptionProgress(): array
    {
        $counts = $this->transcriptsQuery()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(status = ?), 0) as completed', [Transcript::STATUS_COMPLETED])
            ->selectRaw('coalesce(sum(status = ?), 0) as failed', [Transcript::STATUS_FAILED])
            ->first();

        return [
            'total' => (int) $counts->total,
            'completed' => (int) $counts->completed,
            'failed' => (int) $counts->failed,
        ];
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
     * Create the Session and the creator's Session Participant row together,
     * atomically. Returns null if the team already has a non-terminal session,
     * without creating anything. The single seam through which a Session can
     * come into existence, so any future caller (a CLI command, a queued job,
     * this controller) inherits the same locking and one-non-terminal-session
     * invariant for free. The Timeline is not created here: it is created in
     * complete(), when the session enters processing and there is recorded data
     * for it to be the spine of.
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
                ->with('user')
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

            Broadcasting::safely(new SessionStatusChanged($this));

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

            Broadcasting::safely(new SessionStatusChanged($this));

            $this->departActiveParticipants();
        });
    }

    /**
     * Complete this in_progress session from one call that carries an audio and
     * video slot for every recording player. Stores whatever files the slots
     * hold, creates the Timeline, moves in_progress -> processing, sweeps every
     * recording participant to completed, and queues one transcript row plus one
     * SubmitTranscription job per stored AOD. The single seam that owns the
     * completion guards, checked in order: the session must be in_progress; the
     * submitted user ids must match the recording roster exactly; at least one
     * slot must hold both an audio and a video. The stores and the status move
     * run in one transaction, so a session that is not processing never has a
     * stored record attached, and any file written before a mid-transaction
     * failure is deleted on the way out. Transcription jobs are dispatched only
     * once that transaction has committed.
     *
     * @param  list<array{user_id: int, audio: ?UploadedFile, video: ?UploadedFile}>  $entries
     *
     * @throws SessionTransitionException when a completion guard is not met
     */
    public function complete(array $entries, array $gameEvents = []): void
    {
        $writtenPaths = [];
        $transcripts = [];

        try {
            DB::transaction(function () use ($entries, $gameEvents, &$writtenPaths, &$transcripts) {
                $status = self::whereKey($this->getKey())->lockForUpdate()->value('status');

                if ($status !== self::STATUS_IN_PROGRESS) {
                    throw new SessionTransitionException('Only an in_progress session can be completed.');
                }

                $recording = $this->participants()
                    ->where('participant_status', SessionParticipant::PARTICIPANT_STATUS_RECORDING)
                    ->with('user')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('user_id');

                $slots = collect($entries);

                $this->assertRosterMatch($recording->keys(), $slots->pluck('user_id'));

                $hasFullPair = $slots->contains(fn (array $entry) => $entry['audio'] && $entry['video']);

                if (! $hasFullPair) {
                    throw new SessionTransitionException('At least one player must provide both an audio and a video recording.');
                }

                foreach ($entries as $entry) {
                    $participant = $recording->get($entry['user_id']);

                    if ($entry['audio']) {
                        $writtenPaths[] = $path = $this->storeRecording($entry['audio'], $entry['user_id'], 'aod');
                        $aod = AodRecord::create($this->recordAttributes($participant, $entry['audio'], $path));
                        $transcripts[] = Transcript::create([
                            'aod_record_id' => $aod->id,
                            'provider' => Transcript::PROVIDER_ASSEMBLYAI,
                            'status' => Transcript::STATUS_QUEUED,
                        ]);
                    }

                    if ($entry['video']) {
                        $writtenPaths[] = $path = $this->storeRecording($entry['video'], $entry['user_id'], 'vod');
                        VodRecord::create($this->recordAttributes($participant, $entry['video'], $path));
                    }
                }

                foreach ($gameEvents as $event) {
                    $this->gameEvents()->create([
                        'source' => 'manual',
                        'type' => $event['type'],
                        'side' => $event['side'] ?? null,
                        'match_time_ms' => $event['match_time_ms'],
                        'round_number' => $event['round_number'] ?? null,
                        'note' => $event['note'] ?? null,
                        'raw' => $event,
                    ]);
                }

                $this->update(['status' => self::STATUS_PROCESSING]);

                Timeline::firstOrCreate(['session_id' => $this->id]);

                Broadcasting::safely(new SessionStatusChanged($this));

                $recording->each(
                    fn (SessionParticipant $swept) => $swept->advanceStatusTo(SessionParticipant::PARTICIPANT_STATUS_COMPLETED),
                );
            });
        } catch (Throwable $e) {
            foreach ($writtenPaths as $path) {
                Storage::disk(self::RECORDING_DISK)->delete($path);
            }

            throw $e;
        }

        // Dispatched only after the transaction has committed, so a worker
        // never picks up a transcript row that a rolled-back completion left
        // behind.
        foreach ($transcripts as $transcript) {
            SubmitTranscription::dispatch($transcript);
        }
    }

    /**
     * Rebuild this session's timeline from its already-stored recordings using
     * the team's current settings. The escape hatch for a coach who re-tuned
     * the team's Team Keywords or Communication Event Padding after the session
     * was first analysed and wants the timeline to reflect the change; no files
     * are re-uploaded and transcription is not re-run.
     *
     * Moves timeline_ready -> processing, clears the detection flag on every
     * completed transcript, and re-dispatches detection for each. DetectCommEvents
     * replaces that transcript's comm_events + callout_detections wholesale, and
     * once every transcript is done again AdvanceSessionAfterProcessing flips the
     * session back to timeline_ready — the same fan-in the first analysis used,
     * so the read endpoints correctly 409 while the rebuild is in flight.
     *
     * Communication events and, when the session has them, game-state alignment
     * are both rebuilt, so "re-analyze" always means the whole timeline.
     *
     * @throws SessionTransitionException when the session has no ready timeline
     *                                    to rebuild, or no transcribed recording
     */
    public function reanalyze(): void
    {
        $transcripts = DB::transaction(function () {
            $status = self::whereKey($this->getKey())->lockForUpdate()->value('status');

            if ($status !== self::STATUS_TIMELINE_READY) {
                throw new SessionTransitionException('Only a session with a ready timeline can be re-analyzed.');
            }

            $completed = $this->transcriptsQuery()
                ->where('status', Transcript::STATUS_COMPLETED)
                ->lockForUpdate()
                ->get();

            if ($completed->isEmpty()) {
                throw new SessionTransitionException('This session has no transcribed recording to re-analyze.');
            }

            // Clear the flag the fan-in waits on, in the same write as the
            // status move, so no AdvanceSessionAfterProcessing run can see a
            // processing session with every transcript still marked done.
            Transcript::whereKey($completed->modelKeys())->update(['comm_events_detected' => false]);

            // Drop game-state alignment too: its rows hang off comm events
            // DetectCommEvents is about to rebuild, and its marker must be null
            // so the fan-in re-runs the assessment before advancing (ADR 0008).
            Annotation::query()
                ->where('topic', Annotation::TOPIC_GAME_STATE_ALIGNMENT)
                ->where('annotatable_type', (new CommEvent)->getMorphClass())
                ->whereIn('annotatable_id', CommEvent::query()
                    ->whereIn('transcript_id', $this->transcriptsQuery()->select('id'))
                    ->select('id'))
                ->delete();

            // Drop dead-air detection too: its periods are session-derived and
            // its marker must be null so the fan-in re-runs DetectDeadAir before
            // advancing (ADR 0009).
            Annotation::query()
                ->where('topic', Annotation::TOPIC_DEAD_AIR)
                ->where('annotatable_type', (new DeadAirPeriod)->getMorphClass())
                ->whereIn('annotatable_id', DeadAirPeriod::query()->where('session_id', $this->getKey())->select('id'))
                ->delete();

            DeadAirPeriod::query()->where('session_id', $this->getKey())->delete();

            $this->update([
                'status' => self::STATUS_PROCESSING,
                'game_alignment_assessed_at' => null,
                'dead_air_detected_at' => null,
            ]);

            Broadcasting::safely(new SessionStatusChanged($this));

            return $completed;
        });

        // Dispatched only after commit, same as complete(): a worker must never
        // pick up a transcript a rolled-back re-analysis left flagged.
        foreach ($transcripts as $transcript) {
            DetectCommEvents::dispatch($transcript);
        }
    }

    /**
     * The submitted user ids must be exactly the recording roster. No recording
     * player may be left out, and no id that is not recording in this session
     * may appear.
     *
     * @param  Collection<int, int>  $roster
     * @param  Collection<int, int>  $submitted
     *
     * @throws SessionTransitionException when the two sets differ
     */
    private function assertRosterMatch(Collection $roster, Collection $submitted): void
    {
        $missing = $roster->diff($submitted)->values();
        $unexpected = $submitted->diff($roster)->values();

        if ($missing->isEmpty() && $unexpected->isEmpty()) {
            return;
        }

        $clauses = [];

        if ($missing->isNotEmpty()) {
            $clauses[] = 'Missing: '.$missing->implode(', ');
        }

        if ($unexpected->isNotEmpty()) {
            $clauses[] = 'Not recording: '.$unexpected->implode(', ');
        }

        throw new SessionTransitionException('Completion must cover every recording player. '.implode('. ', $clauses).'.');
    }

    /**
     * Store one uploaded recording under this session's layout and return its
     * disk-relative path. The extension is derived from the validated MIME
     * type, not the client-supplied filename.
     *
     * @throws RuntimeException when the disk write fails (the local disk is
     *                          configured not to throw on its own)
     */
    private function storeRecording(UploadedFile $file, int $userId, string $kind): string
    {
        $name = $kind.'.'.$this->extensionForMime($file->getMimeType());

        $path = $file->storeAs("session-recordings/{$this->id}/{$userId}", $name, self::RECORDING_DISK);

        if ($path === false) {
            throw new RuntimeException("Failed to store the {$kind} recording for session {$this->id}.");
        }

        return $path;
    }

    /**
     * The stored file extension for one of the audio/video MIME types the
     * completion request allows. Falls back to `bin` for anything unrecognised,
     * which the request validation should already have rejected.
     */
    private function extensionForMime(?string $mime): string
    {
        return match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-matroska' => 'mkv',
            default => 'bin',
        };
    }

    /**
     * The column values shared by an aod_records / vod_records row.
     *
     * @return array<string, mixed>
     */
    private function recordAttributes(SessionParticipant $participant, UploadedFile $file, string $path): array
    {
        return [
            'session_participant_id' => $participant->id,
            'disk' => self::RECORDING_DISK,
            'path' => $path,
            'original_filename' => Str::limit($file->getClientOriginalName(), 255, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ];
    }

    /**
     * Record the given user's consent on their own participant row, moving it
     * needs_consent -> ready (idempotent once already ready). The single seam
     * that owns the consent guard. A Coach has nothing to consent to, consent
     * closes once the session is terminal or the row has moved past ready, and
     * only a participant currently in the session can consent at all. Locks
     * and re-reads that participant row inside the transaction so a concurrent
     * move can't be clobbered between the check and the write.
     *
     * @throws SessionTransitionException when consent is not available to this caller
     */
    public function recordConsent(User $user): SessionParticipant
    {
        return DB::transaction(function () use ($user) {
            $participant = $this->participants()
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->with('user')
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
     * decided purely from the role snapshot. A Coach has nothing to consent to
     * and is ready immediately; everyone else must consent first.
     */
    private static function initialParticipantStatus(?string $role): string
    {
        return in_array($role, User::TEAM_COACH_ROLES, true)
            ? SessionParticipant::PARTICIPANT_STATUS_READY
            : SessionParticipant::PARTICIPANT_STATUS_NEEDS_CONSENT;
    }
}
