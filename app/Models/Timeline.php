<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timeline extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }
}
