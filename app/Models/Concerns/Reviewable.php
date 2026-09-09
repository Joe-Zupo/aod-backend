<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A timeline timestamp a coach signs off on during review
 * (docs/adr/0010-timeline-management.md). `reviewed_at` / `reviewed_by` record
 * who checked the row and when; `created_by` is null for a system-generated row
 * and the authoring coach for a hand-made one.
 */
trait Reviewable
{
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markReviewed(User $coach): void
    {
        $this->forceFill(['reviewed_at' => now(), 'reviewed_by' => $coach->getKey()])->save();
    }

    public function clearReview(): void
    {
        $this->forceFill(['reviewed_at' => null, 'reviewed_by' => null])->save();
    }

    public function scopeReviewed(Builder $query): Builder
    {
        return $query->whereNotNull('reviewed_at');
    }

    public function scopeUnreviewed(Builder $query): Builder
    {
        return $query->whereNull('reviewed_at');
    }
}
