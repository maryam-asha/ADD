<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Company;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProviderRequest>
 */
class ProviderRequestFactory extends Factory
{
    protected $model = ProviderRequest::class;

    /** A top-up of a company of the same provider, pending. */
    public function definition(): array
    {
        $provider = Provider::factory();

        return [
            'provider_id' => $provider,
            'submitted_by' => null,
            'type' => ProviderRequestType::WalletTopUp,
            'company_id' => Company::factory()->for($provider),
            'payload' => ['points' => 100, 'payer' => 'company'],
            'status' => ProviderRequestStatus::Pending,
        ];
    }
}
