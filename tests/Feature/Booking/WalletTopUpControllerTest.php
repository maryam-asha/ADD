<?php

namespace Tests\Feature\Booking;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class WalletTopUpControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_operations_can_top_up_a_members_wallet(): void
    {
        $operator = $this->actingAsOperations();
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '25.00',
            'payment_method' => 'cash',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '25.00');

        $transaction = $wallet->fresh()->transactions()->latest('id')->first();
        $this->assertSame($operator->id, $transaction->performed_by_user_id);
        $this->assertSame(PaymentMethod::Cash, $transaction->payment_method);

        $activity = Activity::where('description', 'wallet_top_up')->latest('id')->first();
        $this->assertSame($operator->id, $activity->causer_id);
    }

    public function test_operations_can_top_up_a_companys_wallet(): void
    {
        $this->actingAsOperations();
        $company = Company::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $response = $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'company_id' => $company->id,
            'amount' => '50.00',
            'payment_method' => 'syriatel',
        ]);

        $response->assertCreated();
    }

    public function test_top_up_requires_exactly_one_target(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        $company = Company::factory()->create();

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'company_id' => $company->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_an_invalid_payment_method(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'visa',
        ])->assertStatus(422);
    }

    public function test_top_up_rejects_a_non_positive_amount(): void
    {
        $this->actingAsOperations();
        $member = User::factory()->create();
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '0',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_a_member_cannot_top_up_a_wallet(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->postJson('/api/v1/admin/reception/wallet-top-ups', [
            'user_id' => $member->id,
            'amount' => '10.00',
            'payment_method' => 'cash',
        ])->assertForbidden();
    }
}
