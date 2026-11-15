<?php

namespace Tests\Feature\Kitchen;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\CashReturn;
use App\Domain\Kitchen\Enums\KitchenOrderStatus;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderRefunder;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cash taken for an order that was not made goes back as cash — or as
 * points when the member asks (2026-11-14 spec, decision 4).
 */
class CashReturnsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $member;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);

        $this->branch = Branch::factory()->create();
        $this->member = User::factory()->create();
        $this->member->assignRole('member');

        Role::create(['name' => 'desk', 'guard_name' => 'web'])->givePermissionTo(['kitchen-orders.review', 'dashboard.finance']);
        $this->staff = User::factory()->create();
        $this->staff->assignRole('desk');
        Sanctum::actingAs($this->staff, ['*']);
    }

    /** A cash order, paid at the desk and then turned down: the cash is owed. */
    private function owed(?Branch $branch = null): KitchenOrder
    {
        $order = KitchenOrder::factory()->withoutBill()->create([
            'user_id' => $this->member->id,
            'branch_id' => ($branch ?? $this->branch)->id,
            'status' => KitchenOrderStatus::AwaitingReception,
            'total_points' => 40,
        ]);
        app(PaymentService::class)->settleAtReception(PaymentPurpose::KitchenOrder, $order, PaymentMethod::Cash, null, null, $this->staff);
        $order->forceFill([
            'status' => KitchenOrderStatus::Rejected,
            'rejection_reason' => 'Closed',
            'rejected_at' => now(),
        ])->save();
        DB::transaction(fn () => app(KitchenOrderRefunder::class)->refund($order, 'x'));

        return $order->refresh();
    }

    private function balance(): int
    {
        $wallet = Wallet::query()->firstOrCreate(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);

        return app(WalletService::class)->generalBalance($wallet);
    }

    public function test_list_shows_cash_owed_in_the_branch(): void
    {
        $this->travelTo(now()->subHour());
        $first = $this->owed();
        $this->travelBack();
        $second = $this->owed();
        $this->owed(Branch::factory()->create());
        $resolved = $this->owed();
        $resolved->forceFill(['cash_return' => CashReturn::ReturnedInCash])->save();

        $response = $this->getJson("/api/v1/admin/reception/kitchen-cash-returns?branch_id={$this->branch->id}")->assertOk();

        $this->assertSame([$first->id, $second->id], array_map(fn (array $row) => $row['order']['id'], $response->json('data')));
        $response->assertJsonPath('data.0.points', 40)
            ->assertJsonPath('data.0.received_currency', 'USD')
            ->assertJsonPath('data.0.order.rejection_reason', 'Closed');
        $this->assertNotNull($response->json('data.0.received_amount'));
    }

    public function test_returned_in_cash_moves_no_points(): void
    {
        $order = $this->owed();
        $before = $this->balance();

        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$order->id}/returned")
            ->assertOk()
            ->assertJsonPath('data.order.cash_return', 'returned_in_cash');

        $order->refresh();
        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertSame(CashReturn::ReturnedInCash, $order->cash_return);
        $this->assertSame($this->staff->id, $order->cash_return_resolved_by);
        $this->assertNotNull($order->cash_return_resolved_at);
        $this->assertNotNull($payment->refunded_at);
        $this->assertNull($payment->refund_transaction_id);
        $this->assertSame($before, $this->balance());
        $this->assertTrue(Activity::query()->where('description', 'kitchen_cash_returned')->where('causer_id', $this->staff->id)->exists());
    }

    public function test_credit_as_points_puts_them_in_the_members_wallet(): void
    {
        $order = $this->owed();
        $before = $this->balance();

        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$order->id}/credit-points")
            ->assertOk()
            ->assertJsonPath('data.order.cash_return', 'credited_as_points');

        $order->refresh();
        $payment = Payment::query()->forPayable($order)->sole();
        $this->assertSame(CashReturn::CreditedAsPoints, $order->cash_return);
        $this->assertNotNull($payment->refund_transaction_id);
        $this->assertSame($before + 40, $this->balance());
        $this->assertTrue(Activity::query()->where('description', 'kitchen_cash_credited_as_points')->exists());
    }

    public function test_resolving_twice_is_409(): void
    {
        $order = $this->owed();
        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$order->id}/returned")->assertOk();
        $before = $this->balance();

        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$order->id}/returned")->assertStatus(409);
        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$order->id}/credit-points")->assertStatus(409);
        $this->assertSame($before, $this->balance());

        $none = KitchenOrder::factory()->withoutBill()->create(['user_id' => $this->member->id, 'branch_id' => $this->branch->id]);
        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$none->id}/returned")->assertStatus(409);
    }

    public function test_returned_cash_leaves_the_finance_cash_total(): void
    {
        $this->owed();
        $returned = $this->owed();
        $credited = $this->owed();
        $each = (float) Payment::query()->forPayable($returned)->sole()->received_amount;
        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$returned->id}/returned")->assertOk();
        $this->postJson("/api/v1/admin/reception/kitchen-cash-returns/{$credited->id}/credit-points")->assertOk();

        $cash = $this->getJson('/api/v1/admin/dashboard/finance')->assertOk()->json('data.collected.current.cash');

        // The cash handed back is gone; the cash kept and the cash turned into points still count.
        $this->assertEqualsWithDelta($each * 2, array_sum(array_map(fn (array $row) => (float) $row['amount'], $cash)), 0.001);
    }
}
