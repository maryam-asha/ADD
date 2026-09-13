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
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalletTopUpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S2-BE-06: WalletService::creditGeneral() already exists and is tested —
 * this is the missing reception/admin-facing endpoint over it. No parallel
 * top-up mechanism; reuses the existing wallet transaction categorisation
 * fields exactly as they are.
 */
class WalletTopUpController extends Controller
{
    use LogsSensitiveActions;

    public function balance(Request $request, WalletService $wallets, CurrencyConversionService $conversion): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required_without:company_id', 'prohibits:company_id', 'integer', 'exists:users,id'],
            'company_id' => ['required_without:user_id', 'integer', 'exists:companies,id'],
            'currency' => ['nullable', 'string', 'exists:currencies,code'],
        ]);

        [$ownerType, $ownerId] = ! empty($validated['company_id'])
            ? [OwnerType::Company, (int) $validated['company_id']]
            : [OwnerType::User, (int) $validated['user_id']];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $generalBalance = $this->calculateGeneralBalance($wallet);
        $baseCurrency = Currency::query()->where('is_base', true)->value('code') ?? 'USD';

        $owner = $ownerType === OwnerType::Company
            ? Company::find($ownerId)
            : User::with('companies')->find($ownerId);

        $requestedCurrency = $validated['currency'] ?? null;
        $convertedBalance = null;

        if ($requestedCurrency !== null && $requestedCurrency !== $baseCurrency) {
            $converted = $conversion->convert((float) $generalBalance, $baseCurrency, $requestedCurrency);
            $convertedBalance = $converted !== null ? (string) $converted : null;
        }

        return response()->json([
            'data' => [
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'owner_name' => $ownerType === OwnerType::Company ? $owner?->legal_name : $owner?->name,
                'owner_phone' => $ownerType === OwnerType::Company ? $owner?->contract_ref : $owner?->phone,
                'status' => $owner?->status?->value ?? (string) $owner?->status,
                'general_balance' => (string) $generalBalance,
                'currency' => $baseCurrency,
                'converted_balance' => $convertedBalance,
                'display_currency' => $requestedCurrency ?? $baseCurrency,
            ],
        ]);
    }

    public function store(StoreWalletTopUpRequest $request, WalletService $wallets): JsonResponse
    {
        [$ownerType, $ownerId] = $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $baseCurrency = Currency::query()->where('is_base', true)->value('code') ?? 'USD';
        $paymentCurrency = $request->validated('currency') ?? $baseCurrency;
        $paymentAmount = $request->validated('amount');

        $exchangeRate = null;
        if ($paymentCurrency === $baseCurrency) {
            $creditedAmount = $paymentAmount;
        } else {
            $rateModel = ExchangeRate::current($paymentCurrency);
            $exchangeRate = (float) $rateModel->rate_to_base;
            $creditedAmount = number_format(round(((float) $paymentAmount) * $exchangeRate, 2), 2, '.', '');
        }

        $previousBalance = $this->calculateGeneralBalance($wallet);

        // TODO(spec-C): this endpoint's request and response are still
        // USD-denominated, so the credited amount is converted to points at
        // the ledger boundary here. Task 8 moves the whole path to points.
        $transaction = $wallets->creditGeneral(
            $wallet,
            app(PointConversionService::class)->usdToPoints($creditedAmount),
            WalletTransactionSource::TopUp,
            $request->validated('description')
        );

        $newBalance = bcadd(
            $previousBalance,
            app(PointConversionService::class)->pointsToUsd((int) $transaction->amount),
            2
        );

        $transaction->forceFill([
            'performed_by_user_id' => $request->user()->id,
            'payment_method' => PaymentMethod::from($request->validated('payment_method')),
            'payment_currency' => $paymentCurrency,
            'payment_amount' => $paymentAmount,
            'exchange_rate_to_base' => $exchangeRate !== null ? (string) $exchangeRate : null,
        ])->save();

        $this->logSensitiveAction('wallet_top_up', $transaction, [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'amount' => $creditedAmount,
            'payment_amount' => $paymentAmount,
            'payment_currency' => $paymentCurrency,
            'payment_method' => $request->validated('payment_method'),
        ]);

        $owner = $ownerType === OwnerType::Company
            ? Company::find($ownerId)
            : User::find($ownerId);

        $transactionCode = sprintf('TOP-%s-%04d', now()->format('Ymd'), $transaction->id);

        return response()->json([
            'data' => [
                'id' => $transaction->id,
                'transaction_code' => $transactionCode,
                'amount' => app(PointConversionService::class)->pointsToUsd((int) $transaction->amount),
                'currency' => $baseCurrency,
                'payment_amount' => (string) $paymentAmount,
                'payment_currency' => $paymentCurrency,
                'exchange_rate' => $exchangeRate !== null ? (string) $exchangeRate : null,
                'source' => $transaction->source->value,
                'payment_method' => $transaction->payment_method->value,
                'description' => $transaction->description,
                'performed_by_user_id' => $transaction->performed_by_user_id,
                'performed_by_name' => $request->user()->name,
                'owner_type' => $ownerType->value,
                'owner_id' => $ownerId,
                'recipient_name' => $ownerType === OwnerType::Company ? $owner?->legal_name : $owner?->name,
                'recipient_phone' => $ownerType === OwnerType::Company ? $owner?->contract_ref : $owner?->phone,
                'previous_balance' => (string) $previousBalance,
                'new_balance' => (string) $newBalance,
                'created_at' => $transaction->created_at?->toISOString() ?? now()->toISOString(),
            ],
        ], 201);
    }

    private function calculateGeneralBalance(Wallet $wallet): string
    {
        $transactions = $wallet->transactions()
            ->where('category', WalletTransactionCategory::General)
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        $totalPoints = 0;
        foreach ($transactions as $tx) {
            $totalPoints += (int) $tx->amount;
        }

        return app(PointConversionService::class)->pointsToUsd($totalPoints);
    }
}
