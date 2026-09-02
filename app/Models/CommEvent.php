<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cluster of one or more Team Keyword hits in a single player's transcript,
 * taken as one callout. Typed by the Categories of its hits and flagged
 * redundant when the cluster repeats a Category or keyword. Per player; never
 * merged across players (see docs/adr/0006-communication-events.md).
 */
class CommEvent extends Model
{
    use HasFactory;

    public const TYPE_INFORMATIVE = 'informative';

    public const TYPE_DECLARATIVE = 'declarative';

    public const TYPE_COMPOUND = 'compound';

    protected $fillable = [
        'transcript_id',
        'communication_type',
        'is_redundant',
        'start_ms',
        'end_ms',
        'content',
        'padding_ms',
    ];

    protected $casts = [
        'is_redundant' => 'boolean',
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'padding_ms' => 'integer',
    ];

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }

    public function calloutDetections(): HasMany
    {
        return $this->hasMany(CalloutDetection::class)->orderBy('start_ms');
    }
}
