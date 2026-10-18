<?php

namespace Tests\Feature\Hr;

use Add\Hr\Contracts\ExchangeRateProvider;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Payroll\Services\CompensationService;
use Add\Hr\Time\Models\TimePeriod;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\HrPayrollTrialSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Payroll in ADD: pay in SYP converted at ADD's own exchange rates into a
 * USD run, prepared, approved and paid by people holding the matching
 * permissions, with the payslip notification through ADD's channels. The
 * structure is the trial seeder's (placeholder rates).
 */
class HrPayrollTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(HrPayrollTrialSeeder::class);
        $this->travelTo('2026-04-05 09:00:00');
    }

    public function test_add_rates_convert_through_the_base_currency(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001', 'effective_from' => now()->subDay()]);
        $rates = app(ExchangeRateProvider::class);

        $this->assertSame('0.0001', $rates->rate('SYP', 'USD'));
        $this->assertSame('10000', $rates->rate('USD', 'SYP'));
        $this->assertNull($rates->rate('EUR', 'USD'));
    }

    public function test_a_payroll_is_prepared_approved_and_paid_through_add_permissions(): void
    {
        Notification::fake();
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001', 'effective_from' => now()->subMonths(2)]);
        TimePeriod::query()->create(['year' => 2026, 'month' => 3, 'status' => 'locked']);

        $employeeUser = User::factory()->create();
        $employee = $this->hireFor($employeeUser, ['hired_on' => '2025-01-01']);
        app(CompensationService::class)->change($employee, CarbonImmutable::parse('2026-01-01'), [
            ['pay_component_id' => PayComponent::query()->where('code', 'basic')->value('id'), 'amount' => 10000000, 'currency' => 'SYP'],
        ], null, null);

        Role::create(['name' => 'payroll_officer', 'guard_name' => 'web'])->givePermissionTo(['hr.payroll.view', 'hr.payroll.manage']);
        Role::create(['name' => 'finance_manager', 'guard_name' => 'web'])->givePermissionTo(['hr.payroll.view', 'hr.payroll.approve', 'hr.payroll.pay']);
        $officer = User::factory()->create();
        $officer->assignRole('payroll_officer');
        $finance = User::factory()->create();
        $finance->assignRole('finance_manager');

        Sanctum::actingAs($officer, ['*']);
        $run = $this->postJson('/api/v1/hr/payroll-runs', ['year' => 2026, 'month' => 3, 'kind' => 'regular', 'currency' => 'USD'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/hr/payroll-runs/{$run}/calculate")->assertOk();
        $this->postJson("/api/v1/hr/payroll-runs/{$run}/review")->assertOk();
        $this->postJson("/api/v1/hr/payroll-runs/{$run}/approve")->assertForbidden();

        Sanctum::actingAs($finance, ['*']);
        $this->postJson("/api/v1/hr/payroll-runs/{$run}/approve")->assertOk();
        $this->postJson("/api/v1/hr/payroll-runs/{$run}/pay")->assertOk();

        $slip = Payslip::query()->where('employee_id', $employee->id)->with('lines')->sole();
        $this->assertSame('1000', $slip->lines->firstWhere('code', 'basic')->amount);
        $this->assertSame('SYP', $slip->lines->firstWhere('code', 'basic')->original_currency);
        Notification::assertSentTo($employeeUser, GeneralNotification::class);

        Sanctum::actingAs($employeeUser, ['*']);
        $this->getJson('/api/v1/hr/me/payslips')->assertOk()->assertJsonPath('data.0.gross', '1000');
    }

    public function test_the_trial_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        (new HrPayrollTrialSeeder)->run();
    }
}
