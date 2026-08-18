<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by BookingCreationService when the spending member has more than
 * one wallet that could cover the cost (their own balance and at least one
 * company's) — the client must resubmit with an explicit
 * wallet_owner_type/wallet_owner_id chosen from $options, the same shape
 * WalletController::options() already returns.
 */
class WalletChoiceRequiredException extends RuntimeException
{
    /**
     * @param  list<array{wallet_id: int, owner_type: string, owner_id: int, owner_label: string, category: string, category_balance: string, general_balance: string, usable_balance: string}>  $options
     */
    public function __construct(public readonly array $options)
    {
        parent::__construct('Multiple wallets can cover this booking; an explicit choice is required.');
    }
}
