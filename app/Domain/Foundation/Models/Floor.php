<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Classification/display only — no booking or access logic (PRD decision
 * #2). Setting maintenance on a floor means setting it on every Space
 * underneath individually; there is no status field here to escalate from.
 */
class Floor extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'label',
        'sort_order',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }
}
