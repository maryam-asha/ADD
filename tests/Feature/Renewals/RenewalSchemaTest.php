<?php

namespace Tests\Feature\Renewals;

use App\Domain\Identity\Models\Company;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenewalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_membership_carries_its_renewal_fields_and_chain(): void
    {
        $company = Company::factory()->create();
        $first = Membership::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id, 'auto_renew' => true]);
        $next = Membership::factory()->create([
            'owner_type' => OwnerType::Company, 'owner_id' => $company->id,
            'renewed_from_id' => $first->id,
            'current_period_start' => $first->current_period_end,
            'current_period_end' => $first->current_period_end->copy()->addDays(30),
        ]);
        $first->update(['renewal_stop_reason' => RenewalStopReason::NoSeats]);

        $this->assertTrue($first->fresh()->auto_renew);
        $this->assertSame(RenewalStopReason::NoSeats, $first->fresh()->renewal_stop_reason);
        $this->assertTrue($first->renewal->is($next));
        $this->assertTrue($next->renewedFrom->is($first));
        $this->assertFalse($next->fresh()->auto_renew);
    }

    public function test_a_lease_carries_its_renewal_fields(): void
    {
        $this->seed(CurrencySeeder::class);
        $lease = OfficeLease::factory()->create(['auto_renew' => true]);

        $this->assertTrue($lease->fresh()->auto_renew);
        $this->assertSame(0, $lease->fresh()->renewals_count);
        $this->assertNull($lease->fresh()->renewal_stop_reason);
    }

    public function test_every_reason_has_a_label_in_both_languages(): void
    {
        foreach (RenewalStopReason::cases() as $reason) {
            app()->setLocale('en');
            $this->assertNotSame('api.renewal.reason.'.$reason->value, $reason->label());
            app()->setLocale('ar');
            $this->assertNotSame('api.renewal.reason.'.$reason->value, $reason->label());
        }
    }

    public function test_the_advance_days_setting_defaults_to_seven(): void
    {
        $this->seed(SettingSeeder::class);

        $this->assertSame(7, (int) app(SettingService::class)->get('renewals.advance_days'));
    }
}
