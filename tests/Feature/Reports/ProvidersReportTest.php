<?php

namespace Tests\Feature\Reports;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Models\Provider;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Invoicing\InvoicingTestCase;

class ProvidersReportTest extends InvoicingTestCase
{
    private function hold($company, Branch $branch, int $seats): void
    {
        Membership::factory()->create([
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'branch_id' => $branch->id,
            'seats' => $seats,
        ]);
    }

    public function test_it_summarises_each_provider(): void
    {
        $branch = $this->branchWithSeats(10);
        $empty = Branch::factory()->create(['is_default' => false]);

        $a = Provider::factory()->create(['name' => 'Alpha']);
        $b = Provider::factory()->create(['name' => 'Beta']);
        $a1 = $this->clientCompany($a);
        $a2 = $this->clientCompany($a);
        Company::factory()->create(['provider_id' => $a->id, 'status' => 'inactive']);
        $b1 = $this->clientCompany($b);

        $this->hold($a1, $branch, 3);
        $this->hold($a2, $branch, 2);
        $this->hold($b1, $empty, 4);
        OfficeLease::factory()->create(['company_id' => $a1->id]);

        Invoice::factory()->create(['provider_id' => $a->id, 'company_id' => $a1->id, 'amount' => '100.00', 'due_at' => now()->subDay()->toDateString()]);
        Invoice::factory()->create(['provider_id' => $a->id, 'company_id' => $a1->id, 'amount' => '70.00', 'currency' => 'SYP']);

        $this->actingAsAdmin();
        $rows = collect($this->getJson('/api/v1/admin/reports/providers')->assertOk()->json('data'));

        $alpha = $rows->firstWhere('name', 'Alpha');
        $this->assertSame(2, $alpha['active_companies']);
        $this->assertSame(5, $alpha['seats']);
        $this->assertSame(1, $alpha['active_leases']);
        $this->assertSame($branch->id, $alpha['branch_share'][0]['branch_id']);
        $this->assertSame(10, $alpha['branch_share'][0]['capacity']);
        $this->assertSame('0.50', $alpha['branch_share'][0]['share']);
        $usd = collect($alpha['outstanding'])->firstWhere('currency', 'USD');
        $this->assertSame('100.00', $usd['remaining']);
        $this->assertSame('100.00', $usd['overdue']);
        $this->assertSame('70.00', collect($alpha['outstanding'])->firstWhere('currency', 'SYP')['remaining']);

        $beta = $rows->firstWhere('name', 'Beta');
        $this->assertSame(4, $beta['seats']);
        $this->assertSame(0, $beta['branch_share'][0]['capacity']);
        $this->assertNull($beta['branch_share'][0]['share']);
    }

    public function test_operations_without_the_permission_is_refused(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/reports/providers')->assertForbidden();
    }
}
