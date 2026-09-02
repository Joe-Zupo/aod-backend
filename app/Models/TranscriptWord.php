<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One token of a transcript, with its file-relative start/end in milliseconds.
 * Under the zero-offset assumption these are read as Timeline-relative (see
 * docs/adr/0004-transcription-pipeline.md).
 */
class TranscriptWord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'transcript_id',
        'position',
        'word',
        'start_ms',
        'end_ms',
        'confidence',
    ];

    protected $casts = [
        'position' => 'integer',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'confidence' => 'float',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }
}
