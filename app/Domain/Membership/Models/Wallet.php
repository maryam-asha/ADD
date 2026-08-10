<?php

namespace App\Domain\Membership\Models;

use App\Domain\Membership\Concerns\ValidatesOwner;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Manual-polymorphic owner (docs/decisions/wallet-subscription-ownership.md)
 * — unique per owner, unlike Membership. No stored balance column; see
 * docs/decisions/phase-3-membership-plan-wallet-mechanics.md for why.
 */
class Wallet extends Model
{
    use HasFactory, ValidatesOwner;

    protected $fillable = [
        'owner_type',
        'owner_id',
    ];

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
        ];
    }

    protected static function booted(): void
    {
        static::validateOwnerOnSaving();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
