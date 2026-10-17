<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\OrgUnitFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the organization tree (department, team, ...). Its parent is
 * another unit; OrgTree keeps the tree acyclic.
 */
class OrgUnit extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_org_units';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    protected static function newFactory(): OrgUnitFactory
    {
        return OrgUnitFactory::new();
    }
}
