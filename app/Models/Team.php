<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'team_name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'disbanded_at' => 'datetime',
        ];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['member_role', 'status', 'decided_by', 'decided_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    public function activeMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'active');
    }

    public function pendingMembers(): BelongsToMany
    {
        return $this->members()->wherePivot('status', 'pending');
    }

    /**
     * Generate a unique, shareable team code.
     */
    public static function generateTeamCode(): string
    {
        do {
            $code = 'TM-'.Str::upper(Str::random(8));
        } while (static::where('team_code', $code)->exists());

        return $code;
    }
}
