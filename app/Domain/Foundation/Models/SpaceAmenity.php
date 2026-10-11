<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\AmenityType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which AmenityType values are actually toggled on for one specific space
 * — the admin-facing switches. Cross-checked on write (StoreSpaceRequest)
 * against that space's space_type entry in space_type_amenities, but
 * carries no direct relation to it: SpaceTypeAmenity is keyed by
 * space_type value, not by an FK to any one Space.
 */
class SpaceAmenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'amenity',
    ];

    protected function casts(): array
    {
        return [
            'amenity' => AmenityType::class,
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
