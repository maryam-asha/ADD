<?php

namespace Tests\Feature\Leasing;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Models\OfficeLeasePeriod;
use Database\Seeders\CurrencySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficeLeaseModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurrencySeeder::class);
    }

    public function test_a_lease_gets_a_yearly_reference_and_its_periods(): void
    {
        $lease = OfficeLease::factory()->create();
        OfficeLeasePeriod::factory()->create(['office_lease_id' => $lease->id, 'sequence' => 1]);

        $this->assertMatchesRegularExpression('/^LSE-\d{4}-\d{5}$/', $lease->reference);
        $this->assertSame(OfficeLeaseStatus::Active, $lease->status);
        $this->assertCount(1, $lease->periods);
        $this->assertTrue($lease->company->officeLeases->contains($lease));
    }

    public function test_access_ends_the_day_before_a_termination_date(): void
    {
        $lease = OfficeLease::factory()->create(['starts_on' => '2026-01-01', 'duration_months' => 12, 'billing_cycle_months' => 3, 'ends_on' => '2026-12-31']);
        $this->assertSame('2026-12-31', $lease->accessEndsOn()->toDateString());

        $lease->update(['status' => OfficeLeaseStatus::Terminated, 'terminated_on' => '2026-06-01']);
        $this->assertSame('2026-05-31', $lease->fresh()->accessEndsOn()->toDateString());
        $this->assertTrue($lease->fresh()->grantsAccessOn('2026-05-31'));
        $this->assertFalse($lease->fresh()->grantsAccessOn('2026-06-01'));
        $this->assertFalse($lease->fresh()->grantsAccessOn('2025-12-31'));
    }

    public function test_an_ended_lease_grants_no_access(): void
    {
        $lease = OfficeLease::factory()->create(['status' => OfficeLeaseStatus::Ended]);

        $this->assertFalse($lease->grantsAccessOn(now()->toDateString()));
    }

    public function test_overlapping_uses_the_access_window(): void
    {
        $space = Space::factory()->ofType(SpaceType::Business)->create();
        OfficeLease::factory()->create(['space_id' => $space->id, 'starts_on' => '2026-01-01', 'duration_months' => 6, 'billing_cycle_months' => 1, 'ends_on' => '2026-06-30']);
        OfficeLease::factory()->create([
            'space_id' => $space->id, 'starts_on' => '2026-07-01', 'duration_months' => 6, 'billing_cycle_months' => 1, 'ends_on' => '2026-12-31',
            'status' => OfficeLeaseStatus::Terminated, 'terminated_on' => '2026-09-01',
        ]);

        $this->assertTrue(OfficeLease::query()->overlapping($space->id, '2026-06-30', '2026-07-15')->exists());
        $this->assertTrue(OfficeLease::query()->overlapping($space->id, '2026-08-31', '2026-10-01')->exists());
        $this->assertFalse(OfficeLease::query()->overlapping($space->id, '2026-09-01', '2027-08-31')->exists());
        $this->assertSame(1, OfficeLease::query()->holdingOn('2026-03-10')->count());
        $this->assertSame(0, OfficeLease::query()->holdingOn('2026-09-01')->count());
    }
}
