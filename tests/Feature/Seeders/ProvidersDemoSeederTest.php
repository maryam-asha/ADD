<?php

namespace Tests\Feature\Seeders;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Reports\Services\ProvidersReport;
use App\Domain\Reports\Services\RevenueReport;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanModuleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProvidersDemoSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SpaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvidersDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'providers' => Provider::count(),
            'provider_requests' => ProviderRequest::count(),
            'invoices' => Invoice::count(),
            'office_leases' => OfficeLease::count(),
        ];
    }

    public function test_it_seeds_every_state_and_is_rerunnable(): void
    {
        foreach ([RoleSeeder::class, PermissionSeeder::class, SettingSeeder::class, CurrencySeeder::class, SpaceSeeder::class, PlanModuleSeeder::class, PlanSeeder::class] as $seeder) {
            $this->seed($seeder);
        }

        $this->seed(ProvidersDemoSeeder::class);
        $first = $this->counts();

        $this->seed(ProvidersDemoSeeder::class);
        $this->assertSame($first, $this->counts());
        $this->assertSame(3, $first['providers']);

        $this->assertEqualsCanonicalizing(
            array_map(fn ($c) => $c->value, ProviderRequestStatus::cases()),
            ProviderRequest::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_map(fn ($c) => $c->value, InvoiceStatus::cases()),
            Invoice::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_map(fn ($c) => $c->value, OfficeLeaseStatus::cases()),
            OfficeLease::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->all(),
        );

        // Every request type appears in every status.
        foreach (ProviderRequestType::cases() as $type) {
            foreach (ProviderRequestStatus::cases() as $status) {
                $this->assertTrue(
                    ProviderRequest::query()->where('type', $type)->where('status', $status)->exists(),
                    "No {$type->value} request is {$status->value}.",
                );
            }
        }

        // One overdue invoice in each aging bucket (about 10, 45 and 90 days).
        $overdueDays = Invoice::query()->overdue()->get()->map(fn (Invoice $i) => (int) $i->due_at->diffInDays(now()->startOfDay()));
        $this->assertTrue($overdueDays->contains(fn ($d) => $d >= 1 && $d <= 30), 'No invoice in 0-30 days.');
        $this->assertTrue($overdueDays->contains(fn ($d) => $d >= 31 && $d <= 60), 'No invoice in 31-60 days.');
        $this->assertTrue($overdueDays->contains(fn ($d) => $d >= 61), 'No invoice in 60+ days.');

        $this->assertSame(['USD', 'SYP'], Invoice::query()->distinct()->orderByDesc('currency')->pluck('currency')->all());
        $this->assertSame(1, InvoicePayment::query()->whereNotNull('voided_at')->count());
        $this->assertCount(4, InvoicePayment::query()->distinct()->pluck('method'));

        // The reports have data.
        $revenue = app(RevenueReport::class)->for(null, null, null);
        $this->assertEqualsCanonicalizing(
            ['office_lease', 'membership_renewal', 'wallet_top_up', 'plan_assignment'],
            array_values(array_unique(array_column($revenue['by_source'], 'source'))),
        );
        $this->assertNotEmpty($revenue['top_overdue']);
        $this->assertCount(3, app(ProvidersReport::class)->all());
    }
}
