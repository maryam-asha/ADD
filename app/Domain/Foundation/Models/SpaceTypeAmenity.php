<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\AmenityType;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The admin-editable config matrix: for a given SpaceType, which
 * AmenityType values are even selectable (SpaceTypeAmenityController).
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
            'amenity' => AmenityType::class,
        ];
    }
}
