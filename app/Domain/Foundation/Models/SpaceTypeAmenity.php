<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The admin-editable config matrix: for a given SpaceType, which amenities
 * (by Amenity `key`) are even selectable (SpaceTypeAmenityController).
 * `space_type` is the plain enum value, not an FK — there's no "SpaceType"
 * table, `SpaceType` is a plain PHP enum used the same way elsewhere in
 * this codebase.
 */
class SpaceTypeAmenity extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_type',
        'amenity',
    ];

    protected function casts(): array
    {
        return [
            'space_type' => SpaceType::class,
        ];
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(Amenity::class, 'amenity', 'key');
    }
}
