<?php

namespace App\Domain\Providers\Services;

use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CompanyNotifier;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Services\Notification\NotificationService;

/**
 * Links a newly signed-up member to every company an approved invitation
 * named their phone for, and tells them so. The phone was just verified by
 * OTP, so the invitation cannot be claimed by anyone else.
 */
class CompanyInvitationAcceptor
{
    public function __construct(
        private readonly CompanyNotifier $notices,
        private readonly NotificationService $notifications,
    ) {}

    public function acceptFor(User $user): void
    {
        CompanyInvitation::query()->open()->where('phone', $user->phone)->with('company')->get()
            ->each(function (CompanyInvitation $invitation) use ($user) {
                if (! $invitation->company->members()->where('users.id', $user->id)->exists()) {
                    $invitation->company->members()->attach($user->id, [
                        'door_access_enabled' => $invitation->door_access_enabled,
                        'is_admin' => false,
                    ]);
                    $this->notices->joined($invitation->company, $user);
                    $this->tellProvider($invitation->company, $user);
                }

                $invitation->forceFill(['accepted_user_id' => $user->id, 'accepted_at' => now()])->save();
            });
    }

    /** The provider who asked for this employee hears they joined, in its portal. */
    private function tellProvider(Company $company, User $employee): void
    {
        $accounts = $company->provider?->users()->where('users.status', 'active')->get();

        if ($accounts === null || $accounts->isEmpty()) {
            return;
        }

        $this->notifications->sendToUsers($accounts, 'provider.employee_joined', [
            'name' => (string) $employee->name,
            'company' => (string) $company->legal_name,
        ], ['target_type' => 'company', 'target_id' => (string) $company->id], NotificationAudience::Provider);
    }
}
