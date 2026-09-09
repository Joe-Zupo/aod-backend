<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A team-aggregate stretch of session silence longer than the team's
 * `dead_air_threshold`, written by DetectDeadAir. Every period is persisted;
 * one that has an uncalled game event inside it also carries a `dead_air`
 * annotation (see docs/adr/0009-dead-air-detection.md). `dead_air_threshold_ms`
 * is the value the detector ran under, snapshot on the row.
 */
class DeadAirPeriod extends Model
{
    use HasFactory, Reviewable;

    protected $fillable = [
        'session_id',
        'start_ms',
        'end_ms',
        'dead_air_threshold_ms',
        'reviewed_at',
        'reviewed_by',
        'created_by',
    ];

    protected $casts = [
        'start_ms' => 'integer',
        'end_ms' => 'integer',
        'dead_air_threshold_ms' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    /**
     * The `dead_air` annotation on this period, if a game event went uncalled
     * inside it. System-written today; the table is shared with human notes.
     */
    public function annotations(): MorphMany
    {
        return $this->morphMany(Annotation::class, 'annotatable');
    }
}
