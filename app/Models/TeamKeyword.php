<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamKeyword extends Model
{
    use HasFactory;

    public const CATEGORY_INFORMATIVE = 'informative';

    public const CATEGORY_DECLARATIVE = 'declarative';

    public const DEFAULT_INFORMATIVE_KEYWORDS = [
        'one', 'two', 'three', 'four', 'five',
        'here', 'there', 'committed', 'main', 'ct', 'site',
        'spike', 'planted', 'defused',
        'dead', 'down', 'low', 'full',
        'flashed', 'smoked', 'wallbanged',
        'footsteps', 'heard', 'spotted', 'seen',
        'clear', 'empty', 'split', 'late', 'fast', 'slow',
        'stacked', 'rotated', 'ult', 'heaven', 'hell', 'hookah',
        'A', 'B', 'C', 
    ];

    public const DEFAULT_DECLARATIVE_KEYWORDS = [
        'flashing', 'smoking', 'popping', 'rotating', 'pushing', 'holding',
        'peeking', 'swinging', 'planting', 'defusing', 'reloading', 'healing',
        'executing', 'lurking', 'stacking', 'retaking', 'saving', 'dropping',
        'trading', 'clearing', 'watching', 'covering', 'flanking', 'rushing',
        'baiting', 'faking', 'delaying', 'going', 'taking', 'firing',
        'pre-firing', 'pick', 'picking', 'fragging', 'jiggling', 'stop', 'go', 'ulting',
        'committing', 'fake', 'rush'
    ];

    protected $fillable = [
        'team_settings_id',
        'keyword',
        'category',
    ];

    public function teamSettings(): BelongsTo
    {
        return $this->belongsTo(TeamSettings::class);
    }
}
