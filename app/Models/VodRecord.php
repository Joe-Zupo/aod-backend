<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VodRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_participant_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
    ];

    public function sessionParticipant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class);
    }
}
