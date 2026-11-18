<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Company;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** An unpaid USD invoice of a company of the same provider. */
    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'company_id' => fn (array $attributes) => Company::factory()->create(['provider_id' => $attributes['provider_id']])->id,
            'billed_to' => Payer::Company,
            'provider_request_id' => null,
            'description' => 'Wallet top-up: 1000 points',
            'points' => 1000,
            'currency' => 'USD',
            'computed_amount' => '100.00',
            'amount' => '100.00',
            'exchange_rate_to_base' => '1',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => now()->toDateString(),
            'due_at' => now()->addDays(15)->toDateString(),
        ];
    }
}
