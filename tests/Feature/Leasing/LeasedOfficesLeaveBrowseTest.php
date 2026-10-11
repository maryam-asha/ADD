<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Services\SpaceBrowseService;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;

class LeasedOfficesLeaveBrowseTest extends LeasingTestCase
{
    private function officeCount(Branch $branch): int
    {
        return count(app(SpaceBrowseService::class)->browse(['branch_id' => $branch->id, 'space_type' => 'business'])['spaces']);
    }

    public function test_an_office_is_hidden_while_leased_and_back_afterwards(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $leased = $this->leasableOffice($branch, withLock: false);
        $this->leasableOffice($branch, withLock: false);
        $this->assertSame(2, $this->officeCount($branch));

        $lease = OfficeLease::factory()->create(['space_id' => $leased->id, 'starts_on' => now()->toDateString()]);
        $this->assertSame(1, $this->officeCount($branch));

        $lease->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => now()->toDateString()]);
        $this->assertSame(2, $this->officeCount($branch));
    }

    public function test_an_office_leased_from_next_month_is_still_listed_today(): void
    {
        $branch = Branch::factory()->create(['is_active' => true]);
        $office = $this->leasableOffice($branch, withLock: false);
        OfficeLease::factory()->create(['space_id' => $office->id, 'starts_on' => now()->addMonthNoOverflow()->toDateString()]);

        $this->assertSame(1, $this->officeCount($branch));
    }
}
