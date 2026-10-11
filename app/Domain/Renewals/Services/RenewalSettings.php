<?php

namespace App\Domain\Renewals\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Exceptions\RenewalException;
use Illuminate\Database\Eloquent\Model;

/** Turns auto-renewal on or off; turning it off clears a stop reason. */
class RenewalSettings
{
    public function __construct(private readonly MembershipRenewalService $memberships) {}

    public function setForMembership(Membership $membership, bool $on, ?User $actor): void
    {
        if (! $this->memberships->isRenewable($membership)) {
            throw new RenewalException('api.renewal.not_renewable');
        }

        $this->set($membership, $on, $actor);
    }

    public function setForLease(OfficeLease $lease, bool $on, ?User $actor): void
    {
        if ($lease->status !== OfficeLeaseStatus::Active) {
            throw new RenewalException('api.renewal.lease_not_active', 409);
        }

        $this->set($lease, $on, $actor);
    }

    private function set(Model $subject, bool $on, ?User $actor): void
    {
        $subject->update(['auto_renew' => $on] + ($on ? [] : ['renewal_stop_reason' => null]));

        activity()->causedBy($actor)->performedOn($subject)->withProperties(['auto_renew' => $on])->log('auto_renew_changed');
    }
}
