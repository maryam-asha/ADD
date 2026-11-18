<?php

namespace App\Domain\Foundation\Models;

use App\Concerns\HasTranslations;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserBranchMembership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

use Illuminate\Support\Facades\Cache;

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
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'city' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public static function defaultId(): ?int
    {
        return Cache::rememberForever('default_branch_id', function () {
            return static::where('is_default', true)->value('id')
                ?? static::orderBy('id')->value('id');
        });
    }

    protected static function booted(): void
    {
        static::saving(function (Branch $branch) {
            if ($branch->is_default) {
                static::where('id', '!=', $branch->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });

        static::saved(function () {
            Cache::forget('default_branch_id');
        });

        static::deleted(function (Branch $branch) {
            Cache::forget('default_branch_id');

            if ($branch->is_default) {
                $nextBranch = static::orderBy('id')->first();
                if ($nextBranch) {
                    $nextBranch->update(['is_default' => true]);
                }
            }
        });
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class);
    }

    public function businessHourExceptions(): HasMany
    {
        return $this->hasMany(BusinessHourException::class);
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
