<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Behavioral regression coverage for the Phase 3 build-plan guard "every
 * wallets/memberships row has a non-null owner_type+owner_id, and
 * owner_type='company' requires an active company"
 * (App\Domain\Membership\Concerns\ValidatesOwner). Per repo convention,
 * tests/Guards/ is reserved for static source scans only — this behavioral
 * check lives here instead, alongside WalletServiceTest.php.
 */
class WalletOwnershipGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_requires_a_non_null_owner_type_and_owner_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Wallet::create(['owner_type' => null, 'owner_id' => 1]);
    }

    public function test_wallet_requires_a_non_null_owner_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => null]);
    }

    public function test_wallet_rejects_a_company_owner_id_with_no_matching_company_row(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => 999999]);
    }

    public function test_wallet_rejects_an_inactive_company_owner(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);

        $this->expectException(\InvalidArgumentException::class);

        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
    }

    public function test_wallet_succeeds_for_an_active_company_owner(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);

        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->assertNotNull($wallet->id);
        $this->assertSame(OwnerType::Company, $wallet->owner_type);
        $this->assertSame($company->id, $wallet->owner_id);
    }

    public function test_a_second_wallet_for_the_same_owner_violates_the_unique_index(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);

        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->expectException(QueryException::class);

        Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
    }

    private function membershipAttributes(array $overrides = []): array
    {
        return array_merge([
            'plan_id' => Plan::factory()->create(['is_subscription' => true])->id,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ], $overrides);
    }

    public function test_membership_requires_a_non_null_owner_type_and_owner_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Membership::create($this->membershipAttributes(['owner_type' => null, 'owner_id' => 1]));
    }

    public function test_membership_requires_a_non_null_owner_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Membership::create($this->membershipAttributes(['owner_type' => OwnerType::Company, 'owner_id' => null]));
    }

    public function test_membership_rejects_a_company_owner_id_with_no_matching_company_row(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Membership::create($this->membershipAttributes(['owner_type' => OwnerType::Company, 'owner_id' => 999999]));
    }

    public function test_membership_rejects_an_inactive_company_owner(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);

        $this->expectException(\InvalidArgumentException::class);

        Membership::create($this->membershipAttributes(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]));
    }

    public function test_membership_succeeds_for_an_active_company_owner(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);

        $membership = Membership::create($this->membershipAttributes(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]));

        $this->assertNotNull($membership->id);
        $this->assertSame(OwnerType::Company, $membership->owner_type);
        $this->assertSame($company->id, $membership->owner_id);
    }
}
