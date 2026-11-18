<?php

namespace App\Domain\Membership\Concerns;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;

/**
 * Shared `static::saving()` guard for every manual-polymorphic
 * owner_type/owner_id pair in this domain (`Wallet`, `Membership`) — build
 * plan §Phase 3 guard: every row has a non-null owner_type+owner_id, and
 * owner_type=company requires an active Company
 * (docs/decisions/wallet-subscription-ownership.md).
 */
trait ValidatesOwner
{
    protected static function validateOwnerOnSaving(): void
    {
        static::saving(function ($model) {
            if ($model->owner_type === null || $model->owner_id === null) {
                throw new \InvalidArgumentException(
                    static::class.' requires a non-null owner_type and owner_id.'
                );
            }

            if ($model->owner_type === OwnerType::Company
                && Company::where('id', $model->owner_id)
                    ->where('status', CompanyStatus::Active)
                    ->doesntExist()) {
                throw new \InvalidArgumentException(
                    'owner_type=company requires an existing, active Company for that owner_id.'
                );
            }
        });
    }
}
