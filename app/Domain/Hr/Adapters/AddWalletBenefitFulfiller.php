<?php

namespace App\Domain\Hr\Adapters;

use Add\Hr\Benefits\Enums\BenefitFrequency;
use Add\Hr\Benefits\Exceptions\BenefitFulfillmentFailed;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Contracts\BenefitFulfiller;
use Add\Hr\Contracts\HrClock;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * In-kind benefits in ADD are wallet points on the employee's own member
 * wallet (source `employee_benefit`). `fulfillment.category` picks the
 * pocket: general points never expire; cafe or printing/internet points
 * expire `fulfillment.expires_after_days` after the grant, else at the end
 * of the period granted (a year for a one-time benefit). An employee whose
 * account has no wallet — not a member — is a failure HR sees and retries;
 * no wallet is created here, since provisioning one is the member
 * sign-up's.
 */
class AddWalletBenefitFulfiller implements BenefitFulfiller
{
    /** Pockets a benefit may fill; space-specific points need a space. */
    private const CATEGORIES = [
        WalletTransactionCategory::General,
        WalletTransactionCategory::Cafe,
        WalletTransactionCategory::PrintingInternet,
    ];

    public function __construct(
        private readonly WalletService $wallets,
        private readonly HrClock $clock,
    ) {}

    public function rules(): array
    {
        return [
            'amount' => ['integer'],
            'unit' => [Rule::in(['points'])],
            'fulfillment.category' => ['sometimes', Rule::in(array_map(fn (WalletTransactionCategory $c) => $c->value, self::CATEGORIES))],
            'fulfillment.expires_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:366'],
        ];
    }

    public function fulfil(BenefitGrant $grant): ?string
    {
        $points = $grant->amount;

        if (! ctype_digit($points) || (int) $points < 1) {
            throw new BenefitFulfillmentFailed(__('api.hr.benefit_points_whole', ['amount' => $points]));
        }

        $wallet = $grant->employee->user_id === null ? null : Wallet::query()
            ->where('owner_type', OwnerType::User)
            ->where('owner_id', $grant->employee->user_id)
            ->first();

        if ($wallet === null) {
            throw new BenefitFulfillmentFailed(__('api.hr.benefit_no_wallet'));
        }

        $benefit = $grant->benefit;
        $options = $benefit->fulfillment ?? [];
        $category = WalletTransactionCategory::from($options['category'] ?? WalletTransactionCategory::General->value);
        $description = __('api.hr.benefit_wallet_description', ['benefit' => $benefit->translate('name')]);

        $transaction = $category === WalletTransactionCategory::General
            ? $this->wallets->creditGeneral($wallet, (int) $points, WalletTransactionSource::EmployeeBenefit, $description)
            : $this->wallets->creditCategorized($wallet, $category, (int) $points, WalletTransactionSource::EmployeeBenefit,
                $this->expiry($grant, $options['expires_after_days'] ?? null), description: $description);

        return (string) $transaction->id;
    }

    private function expiry(BenefitGrant $grant, ?int $days): CarbonImmutable
    {
        $granted = CarbonImmutable::parse($grant->granted_on->toDateString(), $this->clock->timezone());

        $end = match (true) {
            $days !== null => $granted->addDays($days - 1),
            default => match ($grant->benefit->frequency) {
                BenefitFrequency::Monthly => $granted->endOfMonth(),
                BenefitFrequency::Quarterly => $granted->endOfQuarter(),
                BenefitFrequency::Yearly => $granted->addYear()->subDay(),
                BenefitFrequency::OneTime => $granted->addYear()->subDay(),
            },
        };

        return $end->endOfDay()->utc();
    }
}
