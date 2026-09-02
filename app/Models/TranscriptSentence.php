<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sentence of a transcript as segmented by AssemblyAI's sentences endpoint,
 * in transcript order, with its file-relative start/end in milliseconds and an
 * aggregate confidence. The unit the frontend caption display is built on (see
 * docs/adr/0005-sentence-indexed-transcript-storage.md).
 */
class TranscriptSentence extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'transcript_id',
        'position',
        'text',
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

    public function words(): HasMany
    {
        return $this->hasMany(TranscriptWord::class)->orderBy('sentence_position');
    }
}
