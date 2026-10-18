<?php

namespace Add\Hr\Tests\Fixtures;

use Add\Hr\Benefits\Exceptions\BenefitFulfillmentFailed;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Contracts\BenefitFulfiller;

/**
 * Records every in-kind grant the package asked the host to deliver; fails
 * while `$failWith` is set.
 */
class RecordingBenefitFulfiller implements BenefitFulfiller
{
    /** @var list<int> */
    public array $delivered = [];

    public ?string $failWith = null;

    public function rules(): array
    {
        return ['fulfillment.pocket' => ['sometimes', 'in:general,cafe']];
    }

    public function fulfil(BenefitGrant $grant): ?string
    {
        if ($this->failWith !== null) {
            throw new BenefitFulfillmentFailed($this->failWith);
        }

        $this->delivered[] = $grant->id;

        return 'tx-'.$grant->id;
    }
}
