<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AssemblyAI transcription of one stored AOD file. Its status is the fine
 * grained progress the session's `processing` phase exposes; the session itself
 * carries no per-transcript state.
 */
class Transcript extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PROVIDER_ASSEMBLYAI = 'assemblyai';

    protected $fillable = [
        'aod_record_id',
        'provider',
        'provider_transcript_id',
        'status',
        'text',
        'language_code',
        'confidence',
        'audio_duration_ms',
        'error',
        'raw_response',
        'poll_count',
    ];

    protected $casts = [
        'confidence' => 'float',
        'audio_duration_ms' => 'integer',
        'poll_count' => 'integer',
        'raw_response' => 'array',
    ];

    public function aodRecord(): BelongsTo
    {
        return $this->belongsTo(AodRecord::class);
    }

    public function words(): HasMany
    {
        return $this->hasMany(TranscriptWord::class);
    }

    public function sentences(): HasMany
    {
        return $this->hasMany(TranscriptSentence::class)->orderBy('position');
    }
}
