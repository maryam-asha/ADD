<?php

namespace App\Domain\Foundation\Models;

use App\Concerns\HasTranslations;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserBranchMembership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Top of the spatial hierarchy (docs/decisions/district-removed.md) —
 * Branch itself carries the name and geography that would distinguish a
 * future city or region, so a new branch is the unit that scales, not a
 * District above it.
 */
class Branch extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'name',
        'city',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'city' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function userBranchMemberships(): HasMany
    {
        return $this->hasMany(UserBranchMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch_memberships')
            ->withPivot('is_home_branch')
            ->withTimestamps();
    }
}
