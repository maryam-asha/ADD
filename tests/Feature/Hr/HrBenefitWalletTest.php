<?php

namespace Tests\Feature\Hr;

use Add\Hr\Benefits\Models\BenefitGrant;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Notifications\GeneralNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * In-kind benefits in ADD are points on the employee's member wallet: cafe
 * points expire with the month granted, general points never; an employee
 * who is not a member is a failure HR retries once they have a wallet.
 */
class HrBenefitWalletTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->travelTo('2026-04-05 09:00:00');

        Role::create(['name' => 'hr_officer', 'guard_name' => 'web'])->givePermissionTo(['hr.benefits.view', 'hr.benefits.manage']);
        $this->hr = User::factory()->create();
        $this->hr->assignRole('hr_officer');
        Sanctum::actingAs($this->hr, ['*']);
    }

    private function benefit(array $fulfillment): int
    {
        return $this->postJson('/api/v1/hr/benefits', [
            'code' => 'pts'.uniqid(),
            'name' => ['ar' => 'رصيد مقهى', 'en' => 'Cafe credit'],
            'kind' => 'in_kind_credit',
            'frequency' => 'monthly',
            'amount' => 100,
            'unit' => 'points',
            'employer_cost' => 5,
            'cost_currency' => 'USD',
            'fulfillment' => $fulfillment,
        ])->assertCreated()->json('data.id');
    }

    public function test_cafe_points_reach_the_wallet_and_expire_with_the_month(): void
    {
        Notification::fake();
        $member = User::factory()->create(['preferred_language' => 'ar']);
        $wallet = Wallet::query()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $employee = $this->hireFor($member, ['hired_on' => '2025-01-01']);
        $cafe = $this->benefit(['category' => 'cafe']);
        $general = $this->benefit(['category' => 'general']);
        foreach ([$cafe, $general] as $id) {
            $this->postJson("/api/v1/hr/benefits/{$id}/assignments", ['employee_id' => $employee->id, 'effective_from' => '2026-04-01'])->assertCreated();
        }

        $this->postJson('/api/v1/hr/benefit-grants/run')->assertOk()->assertJsonPath('data.fulfilled', 2);

        $credit = WalletTransaction::query()->where('wallet_id', $wallet->id)->where('category', WalletTransactionCategory::Cafe)->sole();
        $this->assertSame(100, (int) $credit->amount);
        $this->assertSame(WalletTransactionSource::EmployeeBenefit, $credit->source);
        // The end of April in Damascus.
        $this->assertSame('2026-04-30 20:59:59', $credit->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame((string) $credit->id, BenefitGrant::query()->where('benefit_id', $cafe)->value('reference'));

        $this->assertNull(WalletTransaction::query()->where('wallet_id', $wallet->id)->where('category', WalletTransactionCategory::General)->sole()->expires_at);
        Notification::assertSentTo($member, GeneralNotification::class);
    }

    public function test_an_employee_without_a_wallet_fails_until_they_have_one(): void
    {
        $user = User::factory()->create();
        $employee = $this->hireFor($user, ['hired_on' => '2025-01-01']);
        $id = $this->benefit(['category' => 'cafe', 'expires_after_days' => 10]);
        $this->postJson("/api/v1/hr/benefits/{$id}/assignments", ['employee_id' => $employee->id, 'effective_from' => '2026-04-01'])->assertCreated();

        $this->postJson('/api/v1/hr/benefit-grants/run')->assertOk()->assertJsonPath('data.failed', 1);
        $grant = BenefitGrant::query()->sole();
        $this->assertSame(__('api.hr.benefit_no_wallet'), $grant->failure);

        Wallet::query()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $this->postJson("/api/v1/hr/benefit-grants/{$grant->id}/retry")->assertOk()->assertJsonPath('data.status', 'fulfilled');
        $this->assertSame('2026-04-10', WalletTransaction::query()->sole()->expires_at->setTimezone('Asia/Damascus')->toDateString());
    }

    public function test_only_whole_points_into_a_known_pocket(): void
    {
        $this->postJson('/api/v1/hr/benefits', [
            'code' => 'bad', 'name' => ['ar' => 'x'], 'kind' => 'in_kind_credit', 'frequency' => 'monthly',
            'amount' => 2.5, 'unit' => 'hours', 'fulfillment' => ['category' => 'space_specific'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount', 'unit', 'fulfillment.category']);
    }
}
