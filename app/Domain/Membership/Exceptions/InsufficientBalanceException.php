<?php

namespace App\Domain\Membership\Exceptions;

/**
 * Thrown by WalletService when a debit would take a wallet's relevant
 * balance below zero. No special behavior beyond a clear message naming
 * the wallet and the shortfall.
 */
class InsufficientBalanceException extends \RuntimeException {}
