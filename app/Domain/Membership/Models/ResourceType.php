<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The admin-manageable catalog of natural-unit resources a plan can grant a
 * quota of (e.g. "Printing" / unit "page", "Internet" / unit "hour") —
 * docs/decisions/resource-quota-design.md. Not to be confused with
 * App\Domain\Foundation\Models\Resource, unrelated physical equipment
 * metadata on a Space.
 */
class ResourceType extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'slug',
        'name',
        'unit_label',
        'icon',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'unit_label' => 'array',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function planResourceGrants(): HasMany
    {
        return $this->hasMany(PlanResourceGrant::class);
    }
}
