<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\TopUpMinimum;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalletTopUpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S2-BE-06: WalletService::creditGeneral() already exists and is tested —
 * this is the missing reception/admin-facing endpoint over it. No parallel
 * top-up mechanism; reuses the existing wallet transaction categorisation
 * fields exactly as they are.
 *
 * The ledger is point-denominated. The dashboard quotes the points and
 * StoreWalletTopUpRequest verifies them against the cash — floored once on
 * the exact value — so store() credits exactly the figure the operator read
 * out. Every currency figure this controller reports is derived back out of
 * the stored point count, never carried over from the payment, so a receipt
 * can never claim a value the wallet does not hold (Invariant 3).
 */
class WalletTopUpController extends Controller
{
    use LogsSensitiveActions;

    public function balance(
        Request $request,
        WalletService $wallets,
        PointConversionService $points,
        CurrencyConversionService $conversion,
        TopUpMinimum $minimum,
    ): JsonResponse {
        $validated = $request->validate([
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
        ]);

        [$ownerType, $ownerId] = ! empty($validated['company_id'])
            ? [OwnerType::Company, (int) $validated['company_id']]
            : [OwnerType::User, (int) $validated['user_id']];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $generalBalancePoints = $wallets->generalBalance($wallet);
        $baseCurrency = $conversion->baseCurrencyCode();

        $owner = $ownerType === OwnerType::Company
            ? Company::find($ownerId)
            : User::with('companies')->find($ownerId);

        $requestedCurrency = $validated['currency'] ?? null;
        $displayCurrency = $requestedCurrency ?? $baseCurrency;

        $convertedBalance = null;

        if ($requestedCurrency !== null && $requestedCurrency !== $baseCurrency) {
            $convertedBalance = $points->pointsToCurrency($generalBalancePoints, $requestedCurrency);
        }

        return response()->json([
            'data' => [
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'owner_name' => $ownerType === OwnerType::Company ? $owner?->legal_name : $owner?->name,
                'owner_phone' => $ownerType === OwnerType::Company ? $owner?->contract_ref : $owner?->phone,
                'status' => $owner?->status?->value ?? (string) $owner?->status,
                'balance_points' => $generalBalancePoints,
                'balance_usd' => $points->pointsToUsd($generalBalancePoints),
                'balance_display' => $points->pointsToCurrency($generalBalancePoints, $displayCurrency),
                // Retained for the dashboard's current receipt view, which
                // still reads these: both are derived from the point balance
                // above, so they cannot drift from it.
                'general_balance' => $points->pointsToUsd($generalBalancePoints),
                'currency' => $baseCurrency,
                'converted_balance' => $convertedBalance,
                'display_currency' => $displayCurrency,
                // Everything the dashboard needs to convert points <-> cash
                // locally; store() verifies whatever it sends.
                'top_up' => $this->topUpQuoteConfig($points, $conversion, $minimum),
            ],
        ]);
    }

    public function store(
        StoreWalletTopUpRequest $request,
        WalletService $wallets,
        PointConversionService $points,
        CurrencyConversionService $conversion,
    ): JsonResponse {
        [$ownerType, $ownerId] = $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $baseCurrency = $conversion->baseCurrencyCode();
        $paymentCurrency = $request->validated('currency') ?? $baseCurrency;
        $paymentAmount = (string) $request->validated('amount');
        $exchangeRate = $paymentCurrency === $baseCurrency
            ? null
            : (string) ExchangeRate::current($paymentCurrency)->rate_to_base;

        // StoreWalletTopUpRequest has proved this is exactly what the amount
        // buys at today's rates, floored once (Invariant 2), and it is the
        // figure the operator read out to the member.
        $pointsCredited = (int) $request->validated('points');
        $pointRate = $points->rate();

        $previousBalancePoints = $wallets->generalBalance($wallet);

        // One transaction: a credited balance must never exist without the
        // audit trail saying who took the money, in what currency, at what
        // rate. Previously these were two writes with no transaction.
        $transaction = DB::transaction(function () use (
            $wallets, $wallet, $pointsCredited, $request,
            $paymentCurrency, $paymentAmount, $exchangeRate, $pointRate
        ) {
            $transaction = $wallets->creditGeneral(
                $wallet,
                $pointsCredited,
                WalletTransactionSource::TopUp,
                $request->validated('description')
            );

            $transaction->forceFill([
                'performed_by_user_id' => $request->user()->id,
                'payment_method' => PaymentMethod::from($request->validated('payment_method')),
                'payment_currency' => $paymentCurrency,
                'payment_amount' => $paymentAmount,
                'exchange_rate_to_base' => $exchangeRate,
                'point_rate_usd' => $pointRate,
            ])->save();

            return $transaction;
        });

        $newBalancePoints = $previousBalancePoints + $pointsCredited;

        // Every figure logged is either the points actually stored or a
        // verbatim record of what was handed over. The pre-truncation base
        // -currency figure is deliberately absent: it was never credited, so
        // reporting it here would put the audit trail at odds with the ledger.
        $this->logSensitiveAction('wallet_top_up', $transaction, [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'points_credited' => $pointsCredited,
            'payment_amount' => $paymentAmount,
            'payment_currency' => $paymentCurrency,
            'payment_method' => $request->validated('payment_method'),
            'point_rate_usd' => $pointRate,
        ]);

        $owner = $ownerType === OwnerType::Company
            ? Company::find($ownerId)
            : User::find($ownerId);

        return response()->json([
            'data' => [
                'id' => $transaction->id,
                'transaction_code' => sprintf('TOP-%s-%04d', now()->format('Ymd'), $transaction->id),
                'points_credited' => $pointsCredited,
                'previous_balance_points' => $previousBalancePoints,
                'new_balance_points' => $newBalancePoints,
                'point_rate_usd' => (string) $transaction->point_rate_usd,
                'payment_amount' => $paymentAmount,
                'payment_currency' => $paymentCurrency,
                'exchange_rate' => $exchangeRate,
                'source' => $transaction->source->value,
                'payment_method' => $transaction->payment_method->value,
                'description' => $transaction->description,
                'performed_by_user_id' => $transaction->performed_by_user_id,
                'performed_by_name' => $request->user()->name,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'recipient_name' => $ownerType === OwnerType::Company ? $owner?->legal_name : $owner?->name,
                'recipient_phone' => $ownerType === OwnerType::Company ? $owner?->contract_ref : $owner?->phone,
                // Retained for the dashboard's current receipt view. All three
                // are the base-currency value of the points actually credited,
                // so they agree with points_credited by construction — where
                // the old `amount` echoed the untruncated payment and could
                // overstate it by up to one point's worth.
                'amount' => $points->pointsToUsd($pointsCredited),
                'currency' => $baseCurrency,
                'previous_balance' => $points->pointsToUsd($previousBalancePoints),
                'new_balance' => $points->pointsToUsd($newBalancePoints),
                'created_at' => $transaction->created_at?->toISOString() ?? now()->toISOString(),
            ],
        ], 201);
    }

    /**
     * Spec 2026-10-10 §4. A non-base currency with no current rate is left
     * out: it cannot be quoted, and store() refuses it anyway.
     *
     * @return array{point_rate_usd: string, min_points: int, currencies: list<array{code: string, rate_to_base: string, cash_step: string, decimal_places: int}>}
     */
    private function topUpQuoteConfig(
        PointConversionService $points,
        CurrencyConversionService $conversion,
        TopUpMinimum $minimum,
    ): array {
        $baseCurrency = $conversion->baseCurrencyCode();
        $currencies = [];

        foreach (Currency::query()->where('is_active', true)->orderBy('order')->get() as $currency) {
            $rate = $currency->code === $baseCurrency
                ? '1'
                : ExchangeRate::current($currency->code)?->rate_to_base;

            if ($rate === null) {
                continue;
            }

            $currencies[] = [
                'code' => $currency->code,
                'rate_to_base' => (string) $rate,
                'cash_step' => (string) $currency->cash_step,
                'decimal_places' => $currency->decimal_places,
            ];
        }

        return [
            'point_rate_usd' => $points->rate(),
            'min_points' => $minimum->points(),
            'currencies' => $currencies,
        ];
    }
}
