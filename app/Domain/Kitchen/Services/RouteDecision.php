<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Leasing\Models\OfficeLease;

/**
 * Where a member's order goes and how they may pay for it: the branch, the
 * route, the bills open to them, the office lease that made them staff (if
 * any), the payment methods on offer and the wallets they could spend from.
 */
final readonly class RouteDecision
{
    /**
     * @param  list<Booking|WalkinSession>  $bills
     * @param  list<string>  $methods  a subset of bill, wallet, card, cash, in that order
     * @param  list<array<string, mixed>>  $wallets  WalletService::spendOptions() rows, balances included
     */
    public function __construct(
        public ?int $branchId,
        public OrderRoute $route,
        public array $bills,
        public ?OfficeLease $lease,
        public array $methods,
        public array $wallets,
    ) {}
}
