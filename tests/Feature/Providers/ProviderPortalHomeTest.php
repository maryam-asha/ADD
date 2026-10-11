<?php

namespace Tests\Feature\Providers;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use Database\Seeders\CurrencySeeder;

class ProviderPortalHomeTest extends ProviderTestCase
{
    public function test_profile_is_the_accounts_own_provider(): void
    {
        $provider = Provider::factory()->create(['name' => 'Acme Partners', 'payment_terms_days' => 30]);
        $this->clientCompany($provider);
        $this->clientCompany(Provider::factory()->create());
        $this->providerAccount($provider);

        $this->getJson('/api/v1/provider/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $provider->id)
            ->assertJsonPath('data.name', 'Acme Partners')
            ->assertJsonPath('data.payment_terms_days', 30)
            ->assertJsonPath('data.companies_count', 1)
            ->assertJsonMissingPath('data.users');
    }

    public function test_summary_counts_only_this_providers_records(): void
    {
        $this->seed(CurrencySeeder::class);
        $provider = Provider::factory()->create();
        $active = $this->clientCompany($provider);
        $this->clientCompany($provider)->update(['status' => CompanyStatus::Inactive]);
        $active->members()->attach(User::factory()->count(2)->create()->pluck('id'));

        ProviderRequest::factory()->create(['provider_id' => $provider->id, 'company_id' => $active->id]);
        ProviderRequest::factory()->create(['provider_id' => $provider->id, 'company_id' => $active->id, 'status' => ProviderRequestStatus::Rejected]);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id]);
        PrivateOfficeRequest::factory()->create(['provider_id' => $provider->id, 'status' => PrivateOfficeRequestStatus::Contracted]);

        $invoice = fn (array $attributes) => Invoice::factory()->create([
            'provider_id' => $provider->id, 'company_id' => $active->id, ...$attributes,
        ]);
        $invoice(['billed_to' => Payer::Provider, 'amount' => '100.00', 'paid_amount' => '40.00', 'status' => InvoiceStatus::PartiallyPaid]);
        $invoice(['billed_to' => Payer::Company, 'amount' => '50.00', 'due_at' => now()->subDay()->toDateString()]);
        $invoice(['billed_to' => Payer::Provider, 'amount' => '70.00', 'paid_amount' => '70.00', 'status' => InvoiceStatus::Paid]);
        $invoice(['billed_to' => Payer::Provider, 'amount' => '30.00', 'status' => InvoiceStatus::Cancelled]);
        $invoice(['billed_to' => Payer::Provider, 'currency' => 'SYP', 'amount' => '500000.00']);

        // Another provider's records never count.
        $other = Provider::factory()->create();
        ProviderRequest::factory()->create(['provider_id' => $other->id]);
        Invoice::factory()->create(['provider_id' => $other->id]);

        $this->providerAccount($provider);

        $this->getJson('/api/v1/provider/summary')
            ->assertOk()
            ->assertJsonPath('data.companies.total', 2)
            ->assertJsonPath('data.companies.active', 1)
            ->assertJsonPath('data.employees', 2)
            ->assertJsonPath('data.pending_requests', 1)
            ->assertJsonPath('data.open_office_requests', 1)
            ->assertJsonPath('data.invoices.open', 3)
            ->assertJsonPath('data.invoices.overdue', 1)
            ->assertJsonPath('data.invoices.outstanding', [
                ['currency' => 'SYP', 'owed_by_provider' => '500000.00', 'owed_by_companies' => '0.00', 'overdue' => '0.00'],
                ['currency' => 'USD', 'owed_by_provider' => '60.00', 'owed_by_companies' => '50.00', 'overdue' => '50.00'],
            ]);
    }

    public function test_summary_of_a_new_provider_is_all_zero(): void
    {
        $this->providerAccount();

        $this->getJson('/api/v1/provider/summary')
            ->assertOk()
            ->assertJsonPath('data.companies.total', 0)
            ->assertJsonPath('data.invoices.outstanding', []);
    }

    public function test_admins_cannot_reach_the_portal_home(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/provider/summary')->assertForbidden();
        $this->getJson('/api/v1/provider/profile')->assertForbidden();
    }
}
