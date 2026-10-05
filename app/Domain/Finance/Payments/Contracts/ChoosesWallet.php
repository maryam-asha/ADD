<?php

namespace App\Domain\Finance\Payments\Contracts;

/**
 * A method that spends from a wallet the payer names, and checks itself
 * that the payer may spend from it (WalletMethod: their own, or a company
 * they belong to).
 *
 * Only such a method is given the `wallet_owner_type`/`wallet_owner_id` a
 * client sends. Every other method takes money that arrives from outside
 * (a card, cash at the desk), and that money always lands in the payer's
 * own wallet first — PaymentService opens it there, whatever the request
 * named.
 */
interface ChoosesWallet extends PaymentMethodDriver {}
