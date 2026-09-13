<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises WalletService directly (no HTTP) against the debit-resolution
 * algorithm in docs/decisions/phase-3-membership-plan-wallet-mechanics.md.
 */
class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new WalletService;
    }

    /**
     * Raw sum of every transaction (credit and debit) in a category,
     * ignoring expiry/eligibility — used to assert exact remainders in
     * tests where expiry/restriction isn't the thing under test.
     */
    private function rawSum(Wallet $wallet, WalletTransactionCategory $category): int
    {
        $total = 0;

        foreach (WalletTransaction::where('wallet_id', $wallet->id)->where('category', $category)->get() as $transaction) {
            $total += (int) $transaction->amount;
        }

        return $total;
    }

    public function test_credit_general_then_debit_general_for_less_than_balance_succeeds_and_leaves_remainder(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, 200, WalletTransactionSource::TopUp);

        $debit = $this->service->debitGeneral($wallet, 50);

        $this->assertSame(WalletTransactionCategory::General, $debit->category);
        $this->assertSame(-50, (int) $debit->amount);
        $this->assertNull($debit->expires_at);
        $this->assertSame(150, $this->rawSum($wallet, WalletTransactionCategory::General));
    }

    public function test_debiting_more_than_available_general_balance_throws_and_leaves_balance_unchanged(): void
    {
        $wallet = Wallet::factory()->create();

        $this->service->creditGeneral($wallet, 100, WalletTransactionSource::TopUp);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->debitGeneral($wallet, 150);
        } finally {
            $this->assertSame(100, $this->rawSum($wallet, WalletTransactionCategory::General));
        }
    }

    public function test_debit_for_a_category_resolves_against_the_categorized_grant_not_general(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            300,
            WalletTransactionSource::SubscriptionGrant,
            now()->addDays(30)
        );

        $result = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, 100);

        $this->assertCount(1, $result);
        $this->assertSame(WalletTransactionCategory::Cafe, $result[0]->category);
        $this->assertSame(-100, (int) $result[0]->amount);

        $this->assertSame(200, $this->rawSum($wallet, WalletTransactionCategory::Cafe));
        $this->assertSame(1000, $this->rawSum($wallet, WalletTransactionCategory::General));
    }

    public function test_expired_categorized_grant_is_invisible_and_falls_back_to_general(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, 500, WalletTransactionSource::TopUp);
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            300,
            WalletTransactionSource::SubscriptionGrant,
            now()->subDay()
        );

        $result = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, 100);

        $this->assertCount(1, $result);
        $this->assertSame(WalletTransactionCategory::General, $result[0]->category);
        $this->assertSame(-100, (int) $result[0]->amount);

        // The expired grant itself is untouched — it just stopped counting.
        $this->assertSame(300, $this->rawSum($wallet, WalletTransactionCategory::Cafe));
        $this->assertSame(400, $this->rawSum($wallet, WalletTransactionCategory::General));

        $options = $this->service->spendOptions($user, WalletTransactionCategory::Cafe);
        $this->assertCount(1, $options);
        $this->assertSame(0, $options[0]['category_balance']);
        $this->assertSame(400, $options[0]['general_balance']);
        $this->assertSame(400, $options[0]['usable_balance']);
    }

    public function test_grants_restricted_to_different_single_users_are_independent_sub_pools(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $company = Company::factory()->create();
        $company->members()->attach([$userA->id, $userB->id]);

        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->service->creditGeneral($wallet, 500, WalletTransactionSource::TopUp);

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            100,
            WalletTransactionSource::CompanyAdminAllocation,
            now()->addDays(30),
            null,
            [$userA->id]
        );

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            100,
            WalletTransactionSource::CompanyAdminAllocation,
            now()->addDays(30),
            null,
            [$userB->id]
        );

        // A spends their entire 100 points in Cafe.
        $aSpend = $this->service->debit($wallet, $userA, WalletTransactionCategory::Cafe, 100);
        $this->assertSame(WalletTransactionCategory::Cafe, $aSpend[0]->category);

        // B's own 100 is still fully available — unaffected by A's spend.
        $bSpend = $this->service->debit($wallet, $userB, WalletTransactionCategory::Cafe, 100);
        $this->assertCount(1, $bSpend);
        $this->assertSame(WalletTransactionCategory::Cafe, $bSpend[0]->category);
        $this->assertSame(-100, (int) $bSpend[0]->amount);

        // A now has nothing left in Cafe.
        $aOptions = $this->service->spendOptions($userA, WalletTransactionCategory::Cafe);
        $this->assertSame(0, $aOptions[0]['category_balance']);
        $this->assertSame(500, $aOptions[0]['general_balance']);

        // A fresh spend attempt for A falls back to general.
        $aFallback = $this->service->debit($wallet, $userA, WalletTransactionCategory::Cafe, 50);
        $this->assertCount(1, $aFallback);
        $this->assertSame(WalletTransactionCategory::General, $aFallback[0]->category);
    }

    public function test_spend_options_includes_personal_and_company_wallets_and_omits_zero_balance_ones(): void
    {
        $user = User::factory()->create();

        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $this->service->creditGeneral($personalWallet, 200, WalletTransactionSource::TopUp);

        $fundedCompany = Company::factory()->create();
        $fundedCompany->members()->attach($user->id);
        $fundedWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $fundedCompany->id]);
        $this->service->creditGeneral($fundedWallet, 150, WalletTransactionSource::TopUp);

        $emptyCompany = Company::factory()->create();
        $emptyCompany->members()->attach($user->id);
        Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $emptyCompany->id]);

        $options = $this->service->spendOptions($user, WalletTransactionCategory::General);

        $this->assertCount(2, $options);

        $labels = array_column($options, 'owner_label');
        $this->assertContains('Personal', $labels);
        $this->assertContains($fundedCompany->legal_name, $labels);
        $this->assertNotContains($emptyCompany->legal_name, $labels);
    }

    /**
     * Regression: two unrestricted credits sharing the same (empty)
     * restriction signature are one fungible pool of 200 points, not two
     * independent 100-point balances. A first debit of 80 must leave the
     * true pool at 120 — not, as a previous bug in `attemptCategoryDebit()`
     * did, apply the whole 80 debit to *each* credit independently and
     * leave an apparent remainder of 20+20=40.
     */
    public function test_two_unrestricted_credits_sharing_a_signature_are_one_pool_not_double_counted(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            100,
            WalletTransactionSource::Gift,
            now()->addDays(30)
        );
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            100,
            WalletTransactionSource::Gift,
            now()->addDays(30)
        );

        $first = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, 80);
        $this->assertSame(WalletTransactionCategory::Cafe, $first[0]->category);

        // True remaining pool is 200 - 80 = 120, which fully covers a
        // second 80 debit from the category — it must not fall back to
        // (empty) general and throw.
        $second = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, 80);
        $this->assertSame(WalletTransactionCategory::Cafe, $second[0]->category);

        $this->assertSame(40, $this->rawSum($wallet, WalletTransactionCategory::Cafe));
    }

    public function test_two_sequential_debits_drain_balance_to_exactly_zero_and_a_third_fails(): void
    {
        $wallet = Wallet::factory()->create();

        $this->service->creditGeneral($wallet, 101, WalletTransactionSource::TopUp);

        $this->service->debitGeneral($wallet, 33);
        $this->service->debitGeneral($wallet, 68);

        $this->assertSame(0, $this->rawSum($wallet, WalletTransactionCategory::General));

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->debitGeneral($wallet, 1);
        } finally {
            $this->assertSame(0, $this->rawSum($wallet, WalletTransactionCategory::General));
        }
    }
}
