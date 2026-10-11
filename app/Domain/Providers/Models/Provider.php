<?php

namespace App\Domain\Providers\Models;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An intermediary that books seats at ADD for its client companies and has
 * none of its own (docs/decisions/service-providers.md). Created by an
 * admin; its accounts are dashboard accounts holding the `provider` role.
 */
class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'contract_ref',
        'payment_terms_days',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
            'payment_terms_days' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'provider_user')->withTimestamps();
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ProviderRequest::class);
    }

    /** The plans this provider designed. */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function isActive(): bool
    {
        return $this->status === ProviderStatus::Active;
    }

    /** The provider this account works for, whatever its status. */
    public static function forUser(User $user): ?self
    {
        return self::query()
            ->whereHas('users', fn ($query) => $query->where('users.id', $user->id))
            ->first();
    }
}
