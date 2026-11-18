<?php

namespace App\Domain\Providers\Policies;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;

/**
 * The second deliberate exception to flat RBAC after CompanyPolicy
 * (docs/decisions/service-providers.md): which companies a provider account
 * may act on is a fact about `companies.provider_id`, not about a role.
 * Registered as the `provider.manage-company` gate rather than as Company's
 * policy, which CompanyPolicy already is.
 */
class ProviderPolicy
{
    public function manageCompany(User $user, Company $company): bool
    {
        $provider = Provider::forUser($user);

        return $provider !== null && $company->provider_id === $provider->id;
    }
}
