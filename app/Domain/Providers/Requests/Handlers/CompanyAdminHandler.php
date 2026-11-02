<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CompanyNotifier;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

class CompanyAdminHandler implements ProviderRequestHandler
{
    public function __construct(private readonly CompanyNotifier $notices) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $userId = (int) $request->payload['user_id'];
        $company = $request->company;
        $member = $company->members()->where('users.id', $userId)->first();

        if ($member === null) {
            throw new ProviderRequestException('api.provider.not_a_company_member');
        }

        $company->members()->updateExistingPivot($userId, ['is_admin' => true]);

        $this->notices->adminChanged($company, $member, (bool) $member->pivot->is_admin, true, $reviewer);

        return $userId;
    }
}
