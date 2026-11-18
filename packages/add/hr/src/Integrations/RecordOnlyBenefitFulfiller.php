<?php

namespace Add\Hr\Integrations;

use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Contracts\BenefitFulfiller;

/**
 * The default: an in-kind grant is recorded as delivered, nothing more. A
 * host with somewhere to deliver it binds its own.
 */
class RecordOnlyBenefitFulfiller implements BenefitFulfiller
{
    public function rules(): array
    {
        return [];
    }

    public function fulfil(BenefitGrant $grant): ?string
    {
        return null;
    }
}
