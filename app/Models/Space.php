<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'space_type',
        'is_lockable',
        'capacity',
        'hourly_rate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_lockable' => 'boolean',
            'hourly_rate' => 'decimal:2',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
