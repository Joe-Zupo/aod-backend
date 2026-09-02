<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One token of a transcript, with its file-relative start/end in milliseconds.
 * Under the zero-offset assumption these are read as Timeline-relative (see
 * docs/adr/0004-transcription-pipeline.md). Ingested from the sentences response
 * and grouped under its Transcript Sentence (see
 * docs/adr/0005-sentence-indexed-transcript-storage.md): `position` is the
 * transcript-order index, `sentence_position` the index within the sentence.
 */
class TranscriptWord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'transcript_id',
        'transcript_sentence_id',
        'position',
        'sentence_position',
        'word',
        'start_ms',
        'end_ms',
        'confidence',
    ];

    protected $casts = [
        'position' => 'integer',
        'sentence_position' => 'integer',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'confidence' => 'float',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    public function sentence(): BelongsTo
    {
        return $this->belongsTo(TranscriptSentence::class, 'transcript_sentence_id');
    }
}
