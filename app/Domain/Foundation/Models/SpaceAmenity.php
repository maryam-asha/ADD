<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which amenities (by Amenity `key`) are actually toggled on for one
 * specific space — the admin-facing switches. Cross-checked on write
 * (UpdateSpaceAmenitiesRequest) against that space's space_type entry in
 * space_type_amenities, but carries no direct relation to it:
 * SpaceTypeAmenity is keyed by space_type value, not by an FK to any one
 * Space.
 */
class SpaceAmenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'amenity',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity', 'key');
    }
}
