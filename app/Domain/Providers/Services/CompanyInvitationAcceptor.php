<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\CompanyInvitation;

/**
 * Links a newly signed-up member to every company an approved invitation
 * named their phone for. The phone was just verified by OTP, so the
 * invitation cannot be claimed by anyone else.
 */
class CompanyInvitationAcceptor
{
    public function acceptFor(User $user): void
    {
        CompanyInvitation::query()->open()->where('phone', $user->phone)->with('company')->get()
            ->each(function (CompanyInvitation $invitation) use ($user) {
                if (! $invitation->company->members()->where('users.id', $user->id)->exists()) {
                    $invitation->company->members()->attach($user->id, [
                        'door_access_enabled' => $invitation->door_access_enabled,
                        'is_admin' => false,
                    ]);
                }

                $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            });
    }
}
