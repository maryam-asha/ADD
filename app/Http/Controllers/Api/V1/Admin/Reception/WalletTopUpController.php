<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Services\WalletService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreWalletTopUpRequest;
use Illuminate\Http\JsonResponse;

/**
 * S2-BE-06: WalletService::creditGeneral() already exists and is tested —
 * this is the missing reception/admin-facing endpoint over it. No parallel
 * top-up mechanism; reuses the existing wallet transaction categorisation
 * fields exactly as they are.
 */
class WalletTopUpController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreWalletTopUpRequest $request, WalletService $wallets): JsonResponse
    {
        [$ownerType, $ownerId] = $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];

        $wallet = $wallets->walletFor($ownerType, $ownerId);

        $transaction = $wallets->creditGeneral(
            $wallet,
            $request->validated('amount'),
            WalletTransactionSource::TopUp,
            $request->validated('description')
        );

        $transaction->forceFill([
            'performed_by_user_id' => $request->user()->id,
            'payment_method' => PaymentMethod::from($request->validated('payment_method')),
        ])->save();

        $this->logSensitiveAction('wallet_top_up', $transaction, [
            'owner_type' => $ownerType->value,
            'owner_id' => $ownerId,
            'amount' => $request->validated('amount'),
            'payment_method' => $request->validated('payment_method'),
        ]);

        return response()->json([
            'data' => [
                'id' => $transaction->id,
                'amount' => (string) $transaction->amount,
                'source' => $transaction->source->value,
                'payment_method' => $transaction->payment_method->value,
                'performed_by_user_id' => $transaction->performed_by_user_id,
            ],
        ], 201);
    }
}
