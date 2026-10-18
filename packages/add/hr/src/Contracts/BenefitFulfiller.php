<?php

namespace Add\Hr\Contracts;

use Add\Hr\Benefits\Exceptions\BenefitFulfillmentFailed;
use Add\Hr\Benefits\Models\BenefitGrant;

/**
 * Delivers an in-kind benefit grant in the host (wallet points, hours of
 * a service). Called inside the grant's transaction, so a delivery in the
 * same database happens exactly once.
 */
interface BenefitFulfiller
{
    /**
     * Extra validation for an in-kind benefit, keyed like the request body
     * (`fulfillment.category`, `amount`); added to the package's own rules
     * for the same key, never replacing them.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Returns the host's reference for the delivery, if any.
     *
     * @throws BenefitFulfillmentFailed with a reason HR can act on
     */
    public function fulfil(BenefitGrant $grant): ?string;
}
