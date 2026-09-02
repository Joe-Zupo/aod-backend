<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Team Keyword hit inside a Communication Event. The keyword text and
 * Category are snapshot at detection time; a later Team Settings edit does not
 * change a row already stored (see docs/adr/0006-communication-events.md).
 */
class CalloutDetection extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'comm_event_id',
        'transcript_word_id',
        'keyword',
        'normalized_keyword',
        'category',
        'start_ms',
        'end_ms',
        'confidence',
    ];

    protected $casts = [
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'confidence' => 'float',
    ];

    public function commEvent(): BelongsTo
    {
        return $this->belongsTo(CommEvent::class);
    }

    public function transcriptWord(): BelongsTo
    {
        return $this->belongsTo(TranscriptWord::class);
    }
}
