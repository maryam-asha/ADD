<?php

namespace App\Domain\Providers\Enums;

/**
 * Everything a provider asks ADD to grant (docs/decisions/service-providers.md).
 * A new office (a new client company) is not here: it goes through the
 * private-office pipeline, which has a quote and a contract of its own.
 */
enum ProviderRequestType: string
{
    case CompanyMember = 'company_member';
    case CompanyAdmin = 'company_admin';
    case PlanDesign = 'plan_design';
    case PlanAssignment = 'plan_assignment';
    case WalletTopUp = 'wallet_top_up';

    /** Whether a request of this type is about one client company. */
    public function needsCompany(): bool
    {
        return $this !== self::PlanDesign;
    }
}
