<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard's Payments pages: the transactions list with its filters
 * and totals, and the payment methods with how each has been used. Both
 * open to operations.
 */
class AdminPaymentsPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, SettingSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus'));

        $operator = User::factory()->create(['name' => 'Desk Operator']);
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->member = User::factory()->create(['name' => 'Layla Haddad', 'phone' => '0933123456']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $attributes */
    private function payment(string $method, int $points, PaymentStatus $status, string $at, array $attributes = []): Payment
    {
        $factory = Payment::factory();

        if ($method !== 'paymera') {
            $factory = $factory->withoutGateway($method, $points);
        } else {
            $attributes += ['points_to_credit' => $points, 'amount_minor' => $points * 10];
        }

        return $factory->create(array_merge([
            'user_id' => $this->member->id,
            'status' => $status,
            'created_at' => Carbon::parse($at, 'Asia/Damascus')->utc(),
        ], $attributes));
    }

    public function test_the_list_names_the_member_and_who_took_the_cash(): void
    {
        $operator = User::factory()->create(['name' => 'Rami']);
        $this->payment('cash', 50, PaymentStatus::Accepted, '2026-08-17 10:00', [
            'received_amount' => 5000, 'received_currency' => 'SYP', 'received_by' => $operator->id,
        ]);

        $this->getJson('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'Layla Haddad')
            ->assertJsonPath('data.0.user.phone', '0933123456')
            ->assertJsonPath('data.0.received_by_name', 'Rami')
            ->assertJsonPath('data.0.method_label', __('api.payment.methods.cash'));
    }

    public function test_the_list_filters_by_district_day_and_by_search(): void
    {
        $this->payment('wallet', 10, PaymentStatus::Accepted, '2026-08-15 23:30');
        $kept = $this->payment('wallet', 20, PaymentStatus::Accepted, '2026-08-16 00:30');
        $other = User::factory()->create(['name' => 'Omar Saleh']);
        $this->payment('wallet', 30, PaymentStatus::Accepted, '2026-08-16 09:00', ['user_id' => $other->id]);

        $this->getJson('/api/v1/admin/payments?from=2026-08-16&to=2026-08-16&search=layla')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kept->id);

        $this->getJson('/api/v1/admin/payments?search=%23'.$kept->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $kept->id);

        $this->getJson('/api/v1/admin/payments?search=0933123')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_summary_totals_the_same_filtered_payments(): void
    {
        $this->payment('wallet', 40, PaymentStatus::Accepted, '2026-08-17 09:00');
        $this->payment('cash', 60, PaymentStatus::Accepted, '2026-08-17 10:00', ['received_amount' => 6000, 'received_currency' => 'SYP']);
        $this->payment('cash', 25, PaymentStatus::Pending, '2026-08-17 11:00');
        $this->payment('paymera', 100, PaymentStatus::Accepted, '2026-08-17 11:30', ['currency' => 'USD', 'currency_exponent' => 2]);
        // Another day: outside the range.
        $this->payment('wallet', 999, PaymentStatus::Accepted, '2026-08-10 09:00');

        $response = $this->getJson('/api/v1/admin/payments/summary?from=2026-08-17&to=2026-08-17')->assertOk();

        $response->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.by_status.accepted', 3)
            ->assertJsonPath('data.by_status.pending', 1)
            ->assertJsonPath('data.accepted.count', 3)
            ->assertJsonPath('data.accepted.points', 200)
            ->assertJsonPath('data.cash.0.currency', 'SYP')
            ->assertJsonPath('data.card.0.currency', 'USD')
            ->assertJsonPath('data.card.0.amount', '10');

        $this->assertEquals(6000, (float) $response->json('data.cash.0.amount'));

        $cash = collect($response->json('data.by_method'))->firstWhere('method', 'cash');
        $this->assertSame(['count' => 2, 'accepted_count' => 1, 'accepted_points' => 60], array_intersect_key($cash, array_flip(['count', 'accepted_count', 'accepted_points'])));
    }

    public function test_the_summary_is_not_mistaken_for_a_payment(): void
    {
        $this->getJson('/api/v1/admin/payments/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_the_methods_page_lists_every_configured_method_with_its_use(): void
    {
        config(['payments.methods.paymera.enabled' => false]);
        $this->payment('wallet', 40, PaymentStatus::Accepted, '2026-08-17 09:00');
        $this->payment('wallet', 15, PaymentStatus::NeedsReview, '2026-08-17 10:00');

        $methods = collect($this->getJson('/api/v1/admin/payment-methods')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame(['wallet', 'cash', 'paymera'], $methods->keys()->all());

        $this->assertFalse($methods['paymera']['enabled']);
        $this->assertTrue($methods['paymera']['gateway']);
        $this->assertFalse($methods['wallet']['gateway']);

        $this->assertSame(['count' => 2, 'accepted_count' => 1, 'accepted_points' => 40, 'open_count' => 1], array_intersect_key($methods['wallet']['usage'], array_flip(['count', 'accepted_count', 'accepted_points', 'open_count'])));
        $this->assertStringStartsWith('2026-08-17T10:00:00', $methods['wallet']['usage']['last_used_at']);
        $this->assertSame(0, $methods['cash']['usage']['count']);

        $walletTopUp = collect($methods['wallet']['purposes'])->firstWhere('purpose', PaymentPurpose::WalletTopUp->value);
        $this->assertFalse($walletTopUp['supported']);
    }

    public function test_members_cannot_reach_either_page(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/payments/summary')->assertForbidden();
        $this->getJson('/api/v1/admin/payment-methods')->assertForbidden();
    }
}
