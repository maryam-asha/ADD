<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Contracts\ChoosesWallet;
use App\Domain\Finance\Payments\Contracts\PaymentFulfiller;
use App\Domain\Finance\Payments\Contracts\PaymentGateway;
use App\Domain\Finance\Payments\Contracts\PaymentMethodDriver;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\PaymentAmountCalculator;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * The one place a payment turns into points and the points are spent on
 * what was bought — whatever the method, whatever the purpose.
 *
 * Points are the only ledger. Money that arrives (a card, cash at the desk)
 * is first credited to the payment's wallet as points, with the audit
 * columns saying how it came; then what is due is debited from the same
 * wallet in the fulfiller's category; then the fulfiller delivers. A plan
 * bought by card is therefore a credit and a debit, net zero — the same two
 * steps as a top-up followed by a purchase.
 *
 * The payment row makes every step safe to repeat: `wallet_transaction_id`
 * records that the money was credited, `settled_at` that the whole
 * settlement — spend and delivery — is done. Not `status`: a gateway accepts
 * a card payment before its points are spent. Not `debit_transaction_id`: a
 * purchase that costs nothing writes no debit row.
 *
 * Only a Pending payment, or an Accepted one not yet settled, can be
 * settled. Every other status is final — failed, cancelled, expired,
 * reversed, or with a human (NeedsReview) — and settling it would spend
 * points on a purchase nobody is waiting for.
 */
class PaymentService
{
    use DetectsConcurrencyErrors, DetectsLostConnections;

    public function __construct(
        private readonly WalletService $wallets,
        private readonly FulfillerRegistry $fulfillers,
        private readonly PaymentMethodRegistry $methods,
        private readonly PaymentAmountCalculator $amounts,
        private readonly ReceivedMoneyConverter $converter,
        private readonly CurrencyConversionService $currencies,
        private readonly PaymentNotifier $notifier,
        private readonly PriceQuoteService $quotes,
        private readonly CouponLedger $couponLedger,
    ) {}

    /**
     * Opens a payment for `$purpose` by `$method` and hands it to the
     * method's driver, which settles it now (wallet), sends the member to
     * pay (a gateway), or leaves it for the desk.
     *
     * The target is checked before anything is written, and the row records
     * what is due at this moment — the quote the member is paying. It starts
     * on the payer's personal wallet: the account money lands in first. Only
     * a driver that picks the wallet itself and checks the payer may spend
     * from it (ChoosesWallet) is given the wallet the client named; card and
     * cash money never lands anywhere but the payer's own wallet.
     *
     * Everything but a gateway runs in one transaction, so a wallet payment
     * that falls short leaves no row behind. A gateway's start makes an HTTP
     * call and records its own failure on the row — pricing the charge
     * included — so the row is Failed, never left Pending; that record must
     * survive. Nothing due is refused (422 `api.payment.nothing_due`) before
     * a gateway row is written.
     *
     * `$context`: `payable` (the Model paid for), `plan`, `points` (an amount
     * fixed by the caller rather than the fulfiller), `authorized_wallet` (a
     * Wallet the caller has already authorized the payer to buy for — a
     * company admin's company, after CompanyPolicy; never built from request
     * input), and whatever the driver reads (`wallet_owner_type` /
     * `wallet_owner_id` for the wallet).
     *
     * @param  array<string, mixed>  $context
     * @return array{payment: ?Payment, start: PaymentStart} `payment` is null only for cash that writes no row (see CashMethod)
     *
     * @throws ReceptionActionException 'api.payment.method_not_supported' when the method cannot pay for `$purpose`
     */
    public function start(PaymentPurpose $purpose, User $payer, string $method, array $context = []): array
    {
        $driver = $this->methods->get($method);

        if (! $driver->supports($purpose)) {
            throw new ReceptionActionException('api.payment.method_not_supported');
        }

        $fulfiller = $this->fulfillers->for($purpose);
        $coupon = $context['coupon'] ?? null;

        // A coupon is locked on the payment it prices, in the transaction
        // that opens it — a plan's only, here: anything else already has its
        // price, and takes a coupon through CouponApplication. A card opens
        // through StartPayment, so a gateway never reaches this with one.
        if ($coupon !== null && ($purpose !== PaymentPurpose::PlanPurchase || $driver instanceof PaymentGateway)) {
            throw new LogicException('PaymentService::start() locks a coupon on a plan purchase paid in points or cash only.');
        }

        $open = function () use ($purpose, $payer, $driver, $fulfiller, $context, $coupon) {
            // The coupon's row first, so its limits are counted from a fresh
            // snapshot (CouponLedger), and the price it gives — eligibility
            // checked — before anything is written.
            $coupon = $coupon !== null ? $this->couponLedger->lockForRedemption($coupon) : null;
            $quote = $coupon !== null ? $this->quotes->forPlan($context['plan'], $payer, $coupon, now()) : null;

            $payment = new Payment;
            $payment->forceFill([
                'method' => $driver->key(),
                'purpose' => $purpose,
                'status' => PaymentStatus::Pending,
                'user_id' => $payer->id,
                'wallet_id' => $this->openingWalletId($payer, $driver, $context),
                'plan_id' => isset($context['plan']) ? $context['plan']->id : null,
                // A plan bought: its price before any sale or coupon.
                'list_price_points' => isset($context['plan']) ? (int) $context['plan']->price_points : null,
                // The card path's own name for the points a payment buys;
                // nothing is bought with money until funding arrives.
                'points_to_credit' => 0,
            ]);

            if (($context['payable'] ?? null) instanceof Model) {
                $payment->payable()->associate($context['payable']);
            }

            $payment->setRelation('user', $payer);

            $fulfiller->assertPayable($payment);

            // Cash for anything but a plan leaves the target simply unpaid;
            // reception writes the payment when it takes the money.
            if ($driver instanceof CashMethod && ! $driver->createsPendingRowFor($purpose)) {
                return ['payment' => null, 'start' => PaymentStart::payAtReception()];
            }

            // The coupon's use is filed on the row before what is due is read,
            // so the fulfiller prices the payment with it — the wallet check,
            // the debit and every later quote alike.
            if (($applied = $quote?->appliedCoupon()) !== null) {
                $payment->amount_points = 0;
                $payment->save();
                $this->couponLedger->record($applied, $payer, $payment, PricingPurpose::PlanPurchase, $quote->couponBasePoints, $quote->couponPoints);
            }

            $payment->amount_points = isset($context['points']) ? (int) $context['points'] : $fulfiller->pointsDue($payment);

            // A gateway cannot charge nothing; refused before the row exists,
            // so a free target never leaves a payment behind.
            if ($driver instanceof PaymentGateway && $payment->amount_points <= 0) {
                throw new ReceptionActionException('api.payment.nothing_due');
            }

            $payment->save();

            // A plan a coupon made free has nothing to spend or collect:
            // settled on the spot, by whatever method was asked for.
            if ($applied !== null && $payment->amount_points === 0) {
                return ['payment' => $this->settle($payment)->refresh(), 'start' => PaymentStart::done()];
            }

            $start = $driver->start($payment, $context);

            return ['payment' => $payment->refresh(), 'start' => $start];
        };

        return $driver instanceof PaymentGateway ? $open() : DB::transaction($open);
    }

    /**
     * What paying for `$purpose` would cost right now, in points — the same
     * checks and price start() records, without writing anything.
     *
     * @throws ReceptionActionException when the target cannot be paid for (already paid, cancelled, ...)
     */
    public function quote(PaymentPurpose $purpose, ?Model $payable = null, ?Plan $plan = null): int
    {
        $fulfiller = $this->fulfillers->for($purpose);

        $payment = new Payment;
        $payment->forceFill(['purpose' => $purpose, 'plan_id' => $plan?->id]);

        if ($payable !== null) {
            $payment->payable()->associate($payable);
        }

        $fulfiller->assertPayable($payment);

        return $fulfiller->pointsDue($payment);
    }

    /**
     * The wallet a new payment starts on:
     *
     *  - one the caller has already authorized (`authorized_wallet`);
     *  - for a driver that checks it (ChoosesWallet), the one the payer
     *    named;
     *  - otherwise the payer's own. A card's or the desk's money is never
     *    credited to a wallet the request merely named — nothing would have
     *    checked the payer has any right to it.
     *
     * @param  array<string, mixed>  $context
     */
    private function openingWalletId(User $payer, PaymentMethodDriver $driver, array $context): int
    {
        if (($context['authorized_wallet'] ?? null) instanceof Wallet) {
            return $context['authorized_wallet']->id;
        }

        $ownerType = $driver instanceof ChoosesWallet ? ($context['wallet_owner_type'] ?? null) : null;
        $ownerId = $driver instanceof ChoosesWallet ? ($context['wallet_owner_id'] ?? null) : null;

        $named = $ownerType !== null && $ownerId !== null
            ? Wallet::query()
                ->where('owner_type', is_string($ownerType) ? OwnerType::from($ownerType) : $ownerType)
                ->where('owner_id', (int) $ownerId)
                ->value('id')
            : null;

        return $named
            ?? Wallet::query()->where('owner_type', OwnerType::User)->where('owner_id', $payer->id)->value('id')
            ?? throw new ReceptionActionException('api.payment.no_wallet');
    }

    /**
     * Settles a payment in one transaction, with its row locked: credits
     * `$funding` (when money arrived), spends what is due (unless it is a
     * top-up, which only buys the points), fulfils, and marks it Accepted.
     *
     * Settling a payment that is already settled returns it untouched; one
     * in a final state is refused (409 `api.payment.not_settleable`). Any
     * failure — the target can no longer be bought, the wallet is short,
     * fulfilment throws — rolls the whole settlement back, credit included.
     *
     * @throws InsufficientPointsException when the wallet cannot cover what is due
     * @throws ReceptionActionException when the fulfiller refuses the target,
     *                                  or the payment is in a final state
     * @throws LogicException when a top-up is settled without the money
     */
    public function settle(Payment $payment, ?Funding $funding = null): Payment
    {
        $fulfiller = $this->fulfillers->for($payment->purpose);

        return DB::transaction(function () use ($payment, $funding, $fulfiller) {
            $this->lock($payment);

            if ($payment->settled_at !== null) {
                return $payment;
            }

            if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Accepted], true)) {
                throw new ReceptionActionException('api.payment.not_settleable', 409);
            }

            if ($funding !== null && ! $payment->hasBeenCredited()) {
                $this->credit($payment, $funding, __('api.payment.received_description'));
            }

            if ($payment->purpose === PaymentPurpose::WalletTopUp) {
                // A top-up is the money; accepting one that never arrived
                // would record points nobody paid for.
                if (! $payment->hasBeenCredited()) {
                    throw new LogicException("Top-up payment [{$payment->id}] cannot be settled without funding.");
                }
            } else {
                $fulfiller->assertPayable($payment);
                $this->spend($payment, $fulfiller);
            }

            $fulfiller->fulfil($payment);

            $payment->forceFill(['status' => PaymentStatus::Accepted, 'settled_at' => now()])->save();

            return $payment;
        });
    }

    /**
     * Gives a payment's points back, as points, to the wallet that paid —
     * the booking a company paid for refunds the company. Idempotent on
     * `refunded_at`; only a settled payment that spent points has anything
     * to give back.
     *
     * @throws ReceptionActionException 409 'api.payment.not_refundable'
     */
    public function refund(Payment $payment, string $description): void
    {
        DB::transaction(function () use ($payment, $description) {
            $this->lock($payment);

            if ($payment->isRefunded()) {
                return;
            }

            if ($payment->status !== PaymentStatus::Accepted || $payment->debit_transaction_id === null) {
                throw new ReceptionActionException('api.payment.not_refundable', 409);
            }

            $transaction = $this->wallets->creditGeneral(
                $payment->wallet,
                (int) $payment->amount_points,
                WalletTransactionSource::Refund,
                $description,
            );

            $payment->forceFill([
                'refund_transaction_id' => $transaction->id,
                'refunded_at' => now(),
            ])->save();
        });
    }

    /**
     * Reception takes money for something a member owes and settles it, in
     * one transaction: the money becomes points in the payer's wallet (with
     * the audit columns saying how much, in what, and who took it), what is
     * due is spent, and anything over stays in the wallet as points.
     *
     * `$target` is the record paid for (a booking, a session, a
     * registration), or a plan held for cash (its pending payment). The
     * member's own wallet takes the money — for a plan, the wallet the hold
     * was opened on (a company's, for a company purchase).
     *
     * `$amount` null means exactly what is due, quoted in `$currency`
     * (default: the base currency). Too little is refused with the shortage
     * in that currency; nothing is recorded.
     *
     * @throws ReceptionActionException 409 already paid / not settleable, 422 amount_short (params: shortage, currency)
     * @throws PaymentPricingException when `$currency` has no exchange rate
     */
    public function settleAtReception(
        PaymentPurpose $purpose,
        Model|Payment $target,
        PaymentMethod $collectedVia,
        ?string $amount,
        ?string $currency,
        User $operator,
    ): SettledAtReception {
        $fulfiller = $this->fulfillers->for($purpose);
        $currency ??= $this->currencies->baseCurrencyCode();

        return DB::transaction(function () use ($purpose, $target, $collectedVia, $amount, $currency, $operator, $fulfiller) {
            $payment = $target instanceof Payment
                ? $this->heldForCash($target, $purpose)
                : $this->openedAtReception($purpose, $target);

            $fulfiller->assertPayable($payment);
            $due = $fulfiller->pointsDue($payment);

            $handedOver = $amount ?? $this->converter->pointsToAmount($due, $currency);
            $received = $this->converter->toPoints($handedOver, $currency);

            if ($received->points < $due) {
                throw new ReceptionActionException('api.payment.amount_short', 422, [
                    'shortage' => bcsub($this->converter->pointsToAmount($due, $currency), $handedOver, $this->decimals($currency)),
                    'currency' => $currency,
                ]);
            }

            $payment->forceFill([
                'amount_points' => $due,
                'points_to_credit' => $received->points,
                'collected_via' => $collectedVia,
                'received_amount' => $handedOver,
                'received_currency' => $currency,
                'received_by' => $operator->id,
                'received_at' => now(),
            ])->save();

            $this->settle($payment, new Funding(
                points: $received->points,
                method: $collectedVia,
                currency: $currency,
                amount: $handedOver,
                rateToBase: $received->rateToBase,
                pointRateUsd: $received->pointRateUsd,
                performedBy: $operator->id,
            ));

            $this->notifier->received($payment);

            return new SettledAtReception($payment->refresh(), $received->points, (int) $payment->amount_points);
        });
    }

    /**
     * A plan chosen with cash: its hold, still open.
     */
    private function heldForCash(Payment $payment, PaymentPurpose $purpose): Payment
    {
        $this->lock($payment);

        if ($payment->purpose !== $purpose
            || $payment->method !== CashMethod::KEY
            || $payment->status !== PaymentStatus::Pending
            || ($payment->expires_at !== null && $payment->expires_at->isPast())) {
            throw new ReceptionActionException('api.payment.not_settleable', 409);
        }

        return $payment;
    }

    /**
     * A new payment for a record the member owes for, on the member's own
     * wallet: the account the money goes into before it pays.
     */
    private function openedAtReception(PaymentPurpose $purpose, Model $payable): Payment
    {
        $memberId = $payable->getAttribute('user_id');

        // A registration made at the door may have no account at all.
        if ($memberId === null) {
            throw new ReceptionActionException('api.payment.no_wallet');
        }

        // The desk has always been able to take money from a member who
        // never had a wallet opened; the money needs one to pass through.
        $walletId = Wallet::query()->firstOrCreate([
            'owner_type' => OwnerType::User,
            'owner_id' => $memberId,
        ])->id;

        $payment = new Payment;
        $payment->forceFill([
            'method' => CashMethod::KEY,
            'purpose' => $purpose,
            'status' => PaymentStatus::Pending,
            'user_id' => $memberId,
            'wallet_id' => $walletId,
            'points_to_credit' => 0,
        ]);
        $payment->payable()->associate($payable);

        return $payment;
    }

    private function decimals(string $currency): int
    {
        return (int) (Currency::query()->whereKey($currency)->value('decimal_places') ?? 2);
    }

    /**
     * Turns a card payment the gateway has just accepted into points, and
     * spends them on what it was for. Called synchronously, inside
     * SyncPaymentStatus's transaction, by the FulfilAcceptedPayment listener.
     *
     * Two phases, deliberately apart:
     *
     *  1. **The credit** — idempotent on `wallet_transaction_id`. The money
     *     is at the bank, so the points must reach the wallet whatever
     *     happens next; a failure here propagates, the sync rolls back, and
     *     the reconciler tries again.
     *  2. **The spend and the fulfilment** — settle(), idempotent on
     *     `debit_transaction_id`, in its own (nested) transaction. If it
     *     throws — a plan withdrawn or repriced, a full seat pool — only it
     *     rolls back: the points stay with the member, the payment becomes
     *     NeedsReview and an alert goes out. Their money is never silently
     *     absorbed, and no bank reversal is attempted (a human decides).
     *     A deadlock or a lost connection is the exception: the database
     *     rolled back the whole transaction, credit included, so "credited,
     *     needs review" would be false. It propagates, the sync rolls back,
     *     and the reconciler tries again.
     */
    public function fulfilAcceptedGatewayPayment(Payment $payment): void
    {
        if ($payment->hasBeenCredited()) {
            $this->log($payment, 'info', 'credit skipped, already applied', [
                'payment_id' => $payment->id,
                'wallet_transaction_id' => $payment->wallet_transaction_id,
            ]);
        } else {
            $this->creditGatewayPayment($payment);
        }

        $this->spendGatewayCredit($payment);

        // The member paid on the gateway's page; the money landing is news.
        $this->notifier->received($payment);
    }

    /**
     * Phase 1. The same audit values the card flow has always written: the
     * gateway's ledger method, the amount in the currency actually charged
     * (not its USD value — the two columns are read as a pair), the rate
     * that re-derives the USD value later, no point rate, and nobody's hand.
     */
    private function creditGatewayPayment(Payment $payment): void
    {
        $funding = new Funding(
            points: $payment->points_to_credit,
            method: $this->methods->gatewayFor($payment)->ledgerMethod(),
            currency: $payment->currency,
            amount: $this->amounts->minorToCharged($payment->amount_minor),
            rateToBase: $this->amounts->appliedExchangeRate(),
            pointRateUsd: null,
            performedBy: null,
        );

        DB::transaction(fn () => $this->credit(
            $payment,
            $funding,
            __('api.payment.credit_description', ['reference' => $payment->payment_id]),
        ));

        $this->log($payment, 'info', 'points credited', [
            'payment_id' => $payment->id,
            'points' => $payment->points_to_credit,
            'wallet_id' => $payment->wallet_id,
        ]);
    }

    /**
     * Phase 2. Runs only for a payment still Accepted: one already under
     * review is a human's to resolve, and asking again must not quietly
     * retry the purchase behind their back.
     */
    private function spendGatewayCredit(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Accepted) {
            return;
        }

        try {
            $this->settle($payment);
        } catch (Throwable $e) {
            if ($this->causedByConcurrencyError($e) || $this->causedByLostConnection($e)) {
                throw $e;
            }

            // Re-read: the rolled-back settlement may have left values on
            // the model (a debit id) that never reached the database.
            $payment->refresh();

            $payment->forceFill([
                'status' => PaymentStatus::NeedsReview,
                'review_reason' => $this->isAlreadySettled($e)
                    ? 'payable already settled — points kept in wallet'
                    : "payment credited but activation failed ({$payment->purpose->value}): ".$this->reasonFor($e),
            ])->save();

            $this->log($payment, 'alert', 'fulfilment failed after successful payment', [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose->value,
                'plan_id' => $payment->plan_id,
                'points_credited' => $payment->points_to_credit,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Writes the point credit, fills its audit columns, and links it to the
     * payment — in the caller's transaction, so a credited wallet can never
     * exist without the payment recording that it was credited. The audit
     * columns never affect a balance (WalletBalanceIgnoresAuditColumnsTest).
     */
    private function credit(Payment $payment, Funding $funding, string $description): WalletTransaction
    {
        $transaction = $this->wallets->creditGeneral(
            $payment->wallet,
            $funding->points,
            WalletTransactionSource::TopUp,
            $description,
        );

        $transaction->forceFill([
            'payment_method' => $funding->method,
            'payment_currency' => $funding->currency,
            'payment_amount' => $funding->amount,
            'exchange_rate_to_base' => $funding->rateToBase,
            'point_rate_usd' => $funding->pointRateUsd,
            'performed_by_user_id' => $funding->performedBy,
        ])->save();

        $payment->forceFill(['wallet_transaction_id' => $transaction->id])->save();

        return $transaction;
    }

    /**
     * Debits what is due from the payment's wallet, spent by the payer, and
     * records what was actually debited. A category debit can split across
     * several restriction pools; the payment links the first row.
     */
    private function spend(Payment $payment, PaymentFulfiller $fulfiller): void
    {
        // Points bought with this payment's own money are general points;
        // they pay for it, never an allowance the plan gave the member.
        $category = $payment->hasBeenCredited() ? WalletTransactionCategory::General : $fulfiller->walletCategory();
        $due = $fulfiller->pointsDue($payment);

        if ($due <= 0) {
            // Nothing to spend, so no ledger row; settled_at is the record.
            $payment->forceFill(['amount_points' => 0])->save();

            return;
        }

        try {
            $rows = $this->wallets->debit($payment->wallet, $payment->user, $category, $due, $fulfiller->describe($payment));
        } catch (InsufficientBalanceException) {
            throw InsufficientPointsException::for($this->spendableBalance($payment, $category), $due);
        }

        $debited = '0';

        foreach ($rows as $row) {
            $debited = bcsub($debited, (string) $row->amount, 0);
        }

        $payment->forceFill([
            'debit_transaction_id' => $rows[0]->id ?? null,
            'amount_points' => (int) $debited,
        ])->save();
    }

    /**
     * What the debit could have drawn on: the general balance, or — for a
     * category debit, which falls back to general — the larger of the two.
     */
    private function spendableBalance(Payment $payment, WalletTransactionCategory $category): int
    {
        $general = $this->wallets->generalBalance($payment->wallet);

        if ($category === WalletTransactionCategory::General) {
            return $general;
        }

        return max($general, $this->wallets->categoryBalance($payment->wallet, $payment->user, $category));
    }

    /**
     * Locks the payment row and brings the model in line with it, so every
     * decision below is made on the locked, current state rather than on
     * whatever snapshot the caller held.
     */
    private function lock(Payment $payment): void
    {
        $current = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        $payment->setRawAttributes($current->getAttributes(), true);
        $payment->setRelations([]);
    }

    /**
     * Paid another way while the member was paying by card (at the desk,
     * say): nothing failed, the money simply has nothing left to buy.
     */
    private function isAlreadySettled(Throwable $e): bool
    {
        return $e instanceof ReceptionActionException && $e->messageKey === 'api.payment.already_paid';
    }

    /**
     * `review_reason` is read by staff and written in English, like every
     * other reason on the row; a fulfiller's refusal carries a key.
     */
    private function reasonFor(Throwable $e): string
    {
        return $e instanceof ReceptionActionException
            ? (string) __($e->messageKey, $e->params, 'en')
            : $e->getMessage();
    }

    /**
     * Paymera's lines go to its dedicated `paymera` channel, where its
     * operators look; any other method logs to the default channel — the
     * same split SyncPaymentStatus makes.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(Payment $payment, string $level, string $message, array $context = []): void
    {
        if ($payment->method === PaymeraGateway::KEY) {
            Log::channel('paymera')->{$level}("[Paymera] {$message}", $context);

            return;
        }

        Log::{$level}("[Payments] {$message}", $context);
    }
}
