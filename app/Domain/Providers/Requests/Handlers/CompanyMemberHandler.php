<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\CompanyInvitation;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * A member account with this phone joins the company now; otherwise the
 * phone is parked as an invitation, linked when it signs up
 * (CompanyInvitationAcceptor). A phone held by a non-member account (staff,
 * a provider) is never linked: the invitation stays open.
 */
class CompanyMemberHandler implements ProviderRequestHandler
{
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $company = $request->company;
        $phone = $request->payload['phone'];
        $door = (bool) ($request->payload['door_access_enabled'] ?? false);
        $user = User::query()->where('phone', $phone)->first();

        if ($user !== null && $user->hasRole('member')) {
            if (! $company->members()->where('users.id', $user->id)->exists()) {
                $company->members()->attach($user->id, ['door_access_enabled' => $door, 'is_admin' => false]);
            }

            return $user->id;
        }

        CompanyInvitation::query()->firstOrCreate(
            ['company_id' => $company->id, 'phone' => $phone],
            ['door_access_enabled' => $door, 'provider_request_id' => $request->id],
        );

        return null;
    }
}
