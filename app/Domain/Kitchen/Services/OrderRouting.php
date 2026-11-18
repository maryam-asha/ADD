<?php

namespace App\Domain\Kitchen\Services;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use App\Domain\Kitchen\Enums\OrderRoute;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\WalletService;

/**
 * The one answer to "where does this member's order go" (spec decisions 1
 * and 2). A member with a bill, or whose company holds an office here, orders
 * straight to the kitchen; anyone else goes through reception, which is also
 * where cash is taken. Wallet and card are always on offer; the bill only
 * when there is one.
 */
class OrderRouting
{
    public function __construct(
        private readonly BillLocator $bills,
        private readonly OfficeStaff $staff,
        private readonly WalletService $wallets,
    ) {}

    public function for(User $member, ?QrPoint $qr, ?int $branchId): RouteDecision
    {
        $bills = $this->bills->eligibleBills($member);

        $resolved = $qr?->branch_id
            ?? ($bills === [] ? null : (int) $bills[0]->space->building->branch_id)
            ?? $branchId
            // Nothing names a branch: the one the member's office is in, else the default.
            ?? $this->leaseBranch($member)
            ?? Branch::defaultId();

        $lease = $resolved === null ? null : $this->staff->leaseFor($member, (int) $resolved);
        $route = $bills !== [] || $lease !== null ? OrderRoute::Kitchen : OrderRoute::Reception;

        return new RouteDecision(
            branchId: $resolved === null ? null : (int) $resolved,
            route: $route,
            bills: $bills,
            lease: $lease,
            methods: [
                ...($bills === [] ? [] : ['bill']),
                'wallet',
                'card',
                ...($route === OrderRoute::Reception ? ['cash'] : []),
            ],
            wallets: $this->wallets->spendOptions($member, WalletTransactionCategory::General),
        );
    }

    private function leaseBranch(User $member): ?int
    {
        $branchId = $this->staff->anyLeaseFor($member)?->space->building->branch_id;

        return $branchId === null ? null : (int) $branchId;
    }
}
