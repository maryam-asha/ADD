<?php

namespace App\Domain\Foundation\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top of the eight-level spatial hierarchy (PRD decision #1). One real row
 * from day one; meaningful only once a second Branch exists.
 */
class District extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
        ];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
