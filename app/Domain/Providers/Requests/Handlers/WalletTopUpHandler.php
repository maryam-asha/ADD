<?php

namespace App\Domain\Providers\Requests\Handlers;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Providers\Exceptions\ProviderRequestException;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Requests\ProviderRequestHandler;

/**
 * Credits the client company's wallet — the pool its admin allocates to
 * employees from. Not a way of paying ADD: approval issues an invoice
 * for what it costs, through InvoiceIssuer.
 */
class WalletTopUpHandler implements ProviderRequestHandler
{
    public function __construct(private readonly WalletService $wallets) {}

    public function handle(ProviderRequest $request, User $reviewer, array $overrides): ?int
    {
        $wallet = Wallet::query()
            ->where('owner_type', OwnerType::Company)
            ->where('owner_id', $request->company_id)
            ->first() ?? throw new ProviderRequestException('api.provider.company_unavailable');

        $transaction = $this->wallets->creditGeneral(
            $wallet,
            (int) $request->payload['points'],
            WalletTransactionSource::TopUp,
            "Provider request #{$request->id}",
        );

        $transaction->forceFill(['performed_by_user_id' => $reviewer->id])->save();

        return $transaction->id;
    }
}
