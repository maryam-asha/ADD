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
     * Raw bcmath sum of every transaction (credit and debit) in a category,
     * ignoring expiry/eligibility — used to assert exact remainders in
     * tests where expiry/restriction isn't the thing under test.
     */
    private function rawSum(Wallet $wallet, WalletTransactionCategory $category): string
    {
        $total = '0.00';

        foreach (WalletTransaction::where('wallet_id', $wallet->id)->where('category', $category)->get() as $transaction) {
            $total = bcadd($total, (string) $transaction->amount, 2);
        }

        return $total;
    }

    public function test_credit_general_then_debit_general_for_less_than_balance_succeeds_and_leaves_remainder(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, '20.00', WalletTransactionSource::TopUp);

        $debit = $this->service->debitGeneral($wallet, '5.00');

        $this->assertSame(WalletTransactionCategory::General, $debit->category);
        $this->assertSame('-5.00', (string) $debit->amount);
        $this->assertNull($debit->expires_at);
        $this->assertSame('15.00', $this->rawSum($wallet, WalletTransactionCategory::General));
    }

    public function test_debiting_more_than_available_general_balance_throws_and_leaves_balance_unchanged(): void
    {
        $wallet = Wallet::factory()->create();

        $this->service->creditGeneral($wallet, '10.00', WalletTransactionSource::TopUp);

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->debitGeneral($wallet, '15.00');
        } finally {
            $this->assertSame('10.00', $this->rawSum($wallet, WalletTransactionCategory::General));
        }
    }

    public function test_debit_for_a_category_resolves_against_the_categorized_grant_not_general(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, '100.00', WalletTransactionSource::TopUp);
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '30.00',
            WalletTransactionSource::SubscriptionGrant,
            now()->addDays(30)
        );

        $result = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, '10.00');

        $this->assertCount(1, $result);
        $this->assertSame(WalletTransactionCategory::Cafe, $result[0]->category);
        $this->assertSame('-10.00', (string) $result[0]->amount);

        $this->assertSame('20.00', $this->rawSum($wallet, WalletTransactionCategory::Cafe));
        $this->assertSame('100.00', $this->rawSum($wallet, WalletTransactionCategory::General));
    }

    public function test_expired_categorized_grant_is_invisible_and_falls_back_to_general(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '30.00',
            WalletTransactionSource::SubscriptionGrant,
            now()->subDay()
        );

        $result = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, '10.00');

        $this->assertCount(1, $result);
        $this->assertSame(WalletTransactionCategory::General, $result[0]->category);
        $this->assertSame('-10.00', (string) $result[0]->amount);

        // The expired grant itself is untouched — it just stopped counting.
        $this->assertSame('30.00', $this->rawSum($wallet, WalletTransactionCategory::Cafe));
        $this->assertSame('40.00', $this->rawSum($wallet, WalletTransactionCategory::General));

        $options = $this->service->spendOptions($user, WalletTransactionCategory::Cafe);
        $this->assertCount(1, $options);
        $this->assertSame('0.00', $options[0]['category_balance']);
        $this->assertSame('40.00', $options[0]['general_balance']);
        $this->assertSame('40.00', $options[0]['usable_balance']);
    }

    public function test_grants_restricted_to_different_single_users_are_independent_sub_pools(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $company = Company::factory()->create();
        $company->members()->attach([$userA->id, $userB->id]);

        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);

        $this->service->creditGeneral($wallet, '50.00', WalletTransactionSource::TopUp);

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '10.00',
            WalletTransactionSource::CompanyAdminAllocation,
            now()->addDays(30),
            null,
            [$userA->id]
        );

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '10.00',
            WalletTransactionSource::CompanyAdminAllocation,
            now()->addDays(30),
            null,
            [$userB->id]
        );

        // A spends their entire 10 in Cafe.
        $aSpend = $this->service->debit($wallet, $userA, WalletTransactionCategory::Cafe, '10.00');
        $this->assertSame(WalletTransactionCategory::Cafe, $aSpend[0]->category);

        // B's own 10 is still fully available — unaffected by A's spend.
        $bSpend = $this->service->debit($wallet, $userB, WalletTransactionCategory::Cafe, '10.00');
        $this->assertCount(1, $bSpend);
        $this->assertSame(WalletTransactionCategory::Cafe, $bSpend[0]->category);
        $this->assertSame('-10.00', (string) $bSpend[0]->amount);

        // A now has nothing left in Cafe.
        $aOptions = $this->service->spendOptions($userA, WalletTransactionCategory::Cafe);
        $this->assertSame('0.00', $aOptions[0]['category_balance']);
        $this->assertSame('50.00', $aOptions[0]['general_balance']);

        // A fresh spend attempt for A falls back to general.
        $aFallback = $this->service->debit($wallet, $userA, WalletTransactionCategory::Cafe, '5.00');
        $this->assertCount(1, $aFallback);
        $this->assertSame(WalletTransactionCategory::General, $aFallback[0]->category);
    }

    public function test_spend_options_includes_personal_and_company_wallets_and_omits_zero_balance_ones(): void
    {
        $user = User::factory()->create();

        $personalWallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $this->service->creditGeneral($personalWallet, '20.00', WalletTransactionSource::TopUp);

        $fundedCompany = Company::factory()->create();
        $fundedCompany->members()->attach($user->id);
        $fundedWallet = Wallet::factory()->create(['owner_type' => OwnerType::Company, 'owner_id' => $fundedCompany->id]);
        $this->service->creditGeneral($fundedWallet, '15.00', WalletTransactionSource::TopUp);

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
     * restriction signature are one fungible pool of 20.00, not two
     * independent 10.00 balances. A first debit of 8.00 must leave the true
     * pool at 12.00 — not, as a previous bug in `attemptCategoryDebit()`
     * did, apply the whole 8.00 debit to *each* credit independently and
     * leave an apparent remainder of 2.00+2.00=4.00.
     */
    public function test_two_unrestricted_credits_sharing_a_signature_are_one_pool_not_double_counted(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '10.00',
            WalletTransactionSource::Gift,
            now()->addDays(30)
        );
        $this->service->creditCategorized(
            $wallet,
            WalletTransactionCategory::Cafe,
            '10.00',
            WalletTransactionSource::Gift,
            now()->addDays(30)
        );

        $first = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, '8.00');
        $this->assertSame(WalletTransactionCategory::Cafe, $first[0]->category);

        // True remaining pool is 20.00 - 8.00 = 12.00, which fully covers a
        // second 8.00 debit from the category — it must not fall back to
        // (empty) general and throw.
        $second = $this->service->debit($wallet, $user, WalletTransactionCategory::Cafe, '8.00');
        $this->assertSame(WalletTransactionCategory::Cafe, $second[0]->category);

        $this->assertSame('4.00', $this->rawSum($wallet, WalletTransactionCategory::Cafe));
    }

    public function test_two_sequential_debits_drain_balance_to_exactly_zero_and_a_third_fails(): void
    {
        $wallet = Wallet::factory()->create();

        $this->service->creditGeneral($wallet, '10.01', WalletTransactionSource::TopUp);

        $this->service->debitGeneral($wallet, '3.33');
        $this->service->debitGeneral($wallet, '6.68');

        $this->assertSame('0.00', $this->rawSum($wallet, WalletTransactionCategory::General));

        $this->expectException(InsufficientBalanceException::class);

        try {
            $this->service->debitGeneral($wallet, '0.01');
        } finally {
            $this->assertSame('0.00', $this->rawSum($wallet, WalletTransactionCategory::General));
        }
    }
}
