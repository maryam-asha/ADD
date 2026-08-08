<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Classification/display only — no booking or access logic (PRD decision
 * #2). Same reasoning as Floor.
 */
class Zone extends Model
{
    use HasFactory;

    protected $fillable = [
        'floor_id',
        'label',
        'sort_order',
    ];

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
