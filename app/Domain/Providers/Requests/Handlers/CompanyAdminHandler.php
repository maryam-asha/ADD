<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

class CompanyAdminHandler implements ProviderRequestHandler
{
    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $userId = (int) $request->payload['user_id'];
        $company = $request->company;

        if (! $company->members()->where('users.id', $userId)->exists()) {
            throw new ProviderRequestException('api.provider.not_a_company_member');
        }

        $company->members()->updateExistingPivot($userId, ['is_admin' => true]);

        return $userId;
    }
}
