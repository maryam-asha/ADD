<?php

namespace App\Domain\Foundation\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a space offers that is not a physical thing to track — Wi-Fi,
 * café access, a stage. An admin-managed catalog: which ones a space type
 * may offer is SpaceTypeAmenity, which ones a space does is SpaceAmenity,
 * both keyed by `key`. The equipment a space holds (a projector, a mic) is
 * an inventory asset instead, never an amenity.
 */
class Amenity extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'key',
        'name',
        'icon',
        'order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
