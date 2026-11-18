<?php

namespace Tests\Feature\Reports;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Enums\RenewalStopReason;
use Tests\Feature\Invoicing\InvoicingTestCase;

class ProviderCompaniesReportTest extends InvoicingTestCase
{
    public function test_it_summarises_each_of_the_providers_companies(): void
    {
        $provider = Provider::factory()->create();
        $this->providerAccount($provider);
        $a = $this->clientCompany($provider, ['legal_name' => 'Alpha']);
        $b = $this->clientCompany($provider, ['legal_name' => 'Beta']);
        $a->members()->attach(User::factory()->count(2)->create()->pluck('id'));

        Membership::factory()->create([
            'owner_type' => OwnerType::Company,
            'owner_id' => $a->id,
            'seats' => 4,
            'auto_renew' => false,
            'renewal_stop_reason' => RenewalStopReason::NoSeats,
        ]);
        OfficeLease::factory()->create(['company_id' => $a->id]);

        Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $a->id, 'amount' => '100.00', 'due_at' => now()->subDays(3)->toDateString()]);
        Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $a->id, 'amount' => '40.00', 'currency' => 'SYP']);
        Invoice::factory()->create(['provider_id' => $provider->id, 'company_id' => $b->id, 'amount' => '25.00']);

        $other = $this->clientCompany(Provider::factory()->create(), ['legal_name' => 'Other']);

        $data = collect($this->getJson('/api/v1/provider/reports/companies')->assertOk()->json('data'));

        $this->assertSame(['Alpha', 'Beta'], $data->pluck('legal_name')->all());
        $this->assertNotContains($other->id, $data->pluck('id')->all());

        $alpha = $data[0];
        $this->assertSame(2, $alpha['members_count']);
        $this->assertSame(4, $alpha['membership']['seats']);
        $this->assertFalse($alpha['membership']['auto_renew']);
        $this->assertSame('no_seats', $alpha['membership']['renewal_stop_reason']);
        $this->assertNotNull($alpha['membership']['renewal_stop_reason_label']);
        $this->assertSame(1, $alpha['active_leases_count']);
        $usd = collect($alpha['outstanding'])->firstWhere('currency', 'USD');
        $this->assertSame('100', $usd['remaining']);
        $this->assertSame('100', $usd['overdue']);
        $this->assertSame('40', collect($alpha['outstanding'])->firstWhere('currency', 'SYP')['remaining']);

        $beta = $data[1];
        $this->assertNull($beta['membership']);
        $this->assertSame(0, $beta['active_leases_count']);
        $this->assertSame('25', $beta['outstanding'][0]['remaining']);
        $this->assertSame('0', $beta['outstanding'][0]['overdue']);
    }
}
