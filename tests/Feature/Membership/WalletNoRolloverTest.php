<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Behavioral regression coverage for the Phase 3 build-plan guard "unused
 * included hours do not survive a cycle boundary" — the lazy-expiry design
 * from docs/decisions/phase-3-membership-plan-wallet-mechanics.md's "Expiry:
 * lazy, at read time" section: categorized/restricted credits stop counting
 * once past their `expires_at`, without the row itself ever being deleted or
 * zeroed, while General (no `expires_at`) never expires.
 */
class WalletNoRolloverTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        parent::tearDown();
    }

    public function test_a_categorized_credit_stops_counting_after_its_expiry_but_the_row_survives_untouched(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $service = new WalletService;

        $expiresAt = Carbon::now()->addHour();

        $service->creditCategorized(
            $wallet,
            WalletTransactionCategory::SpaceSpecific,
            '10.00',
            WalletTransactionSource::SubscriptionGrant,
            $expiresAt
        );

        // Before the expiry instant: fully usable via the category pool.
        $before = $service->spendOptions($user, WalletTransactionCategory::SpaceSpecific);
        $this->assertCount(1, $before);
        $this->assertSame('10.00', $before[0]['category_balance']);
        $this->assertSame('10.00', $before[0]['usable_balance']);

        // Move time past the expiry instant.
        Carbon::setTestNow($expiresAt->copy()->addMinute());

        try {
            // No longer usable: excluded from the category balance entirely.
            $after = $service->spendOptions($user, WalletTransactionCategory::SpaceSpecific);
            $this->assertCount(0, $after, 'An expired categorized grant with no general fallback should yield no spend options.');

            // A debit attempt falls back to general and, since general is also
            // empty, fails outright rather than drawing on the expired grant.
            $threw = false;

            try {
                $service->debit($wallet, $user, WalletTransactionCategory::SpaceSpecific, '3.00');
            } catch (InsufficientBalanceException) {
                $threw = true;
            }

            $this->assertTrue($threw, 'Expected the debit to fall back to general and fail there, not draw on the expired categorized grant.');

            // The grant row itself still physically exists, amount untouched —
            // expiry is enforced lazily at read time, nothing zeroes it out.
            $grant = WalletTransaction::where('wallet_id', $wallet->id)
                ->where('category', WalletTransactionCategory::SpaceSpecific)
                ->first();

            $this->assertNotNull($grant);
            $this->assertSame('10.00', (string) $grant->amount);
        } finally {
            Carbon::setTestNow(null);
        }
    }

    public function test_general_credit_is_still_fully_counted_400_days_later(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);
        $service = new WalletService;

        $service->creditGeneral($wallet, '25.00', WalletTransactionSource::TopUp);

        Carbon::setTestNow(Carbon::now()->addDays(400));

        try {
            $options = $service->spendOptions($user, WalletTransactionCategory::General);

            $this->assertCount(1, $options);
            $this->assertSame('25.00', $options[0]['general_balance']);
            $this->assertSame('25.00', $options[0]['usable_balance']);

            $debit = $service->debitGeneral($wallet, '10.00');
            $this->assertSame('-10.00', (string) $debit->amount);
        } finally {
            Carbon::setTestNow(null);
        }
    }
}
