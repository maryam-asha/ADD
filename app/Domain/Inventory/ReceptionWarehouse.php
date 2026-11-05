<?php

namespace App\Domain\Inventory;

use Add\Inventory\Warehouses\Models\Warehouse;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Services\CashRegisterService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which warehouse reception takes stock from when it sells or records
 * consumption: the one the desk named, or else the primary warehouse of
 * the branch of the cash register the operator has open — the desk they
 * are standing at. A named one must be active, in that branch when a
 * register is open, and, for a sale, selling. Never another warehouse
 * behind the desk's back: a shortage is fixed with a transfer.
 */
class ReceptionWarehouse
{
    public function __construct(private readonly CashRegisterService $registers) {}

    /**
     * @throws ReceptionActionException 422 when there is none to take from
     */
    public function resolve(?int $warehouseId, User $operator, bool $forSale): Warehouse
    {
        $branchId = $this->branchOf($operator);

        if ($warehouseId === null) {
            $primary = $branchId === null ? null : $this->ofBranch($branchId)->active()->where('is_primary', true)->first();

            return $primary ?? throw new ReceptionActionException('api.reception.no_primary_warehouse');
        }

        $warehouse = Warehouse::query()->active()->find($warehouseId)
            ?? throw new ReceptionActionException('api.reception.warehouse_unavailable');

        if ($forSale && ! $warehouse->allows_sales) {
            throw new ReceptionActionException('api.reception.warehouse_not_for_sale');
        }

        if ($branchId !== null && ($warehouse->place_type !== AddLocationResolver::BRANCH || $warehouse->place_id !== $branchId)) {
            throw new ReceptionActionException('api.reception.warehouse_other_branch');
        }

        return $warehouse;
    }

    /**
     * What the desk may choose from: the active warehouses of the
     * register's branch, or every active one when no register is open;
     * the primary first.
     *
     * @return Collection<int, Warehouse>
     */
    public function options(User $operator): Collection
    {
        $branchId = $this->branchOf($operator);

        return ($branchId === null ? Warehouse::query() : $this->ofBranch($branchId))
            ->active()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * The branch of the register the operator has open, if any.
     */
    public function branchOf(User $operator): ?int
    {
        $branchId = $this->registers->currentSession($operator)?->branch_id;

        return $branchId === null ? null : (int) $branchId;
    }

    /**
     * @return Builder<Warehouse>
     */
    private function ofBranch(int $branchId)
    {
        return Warehouse::query()->where('place_type', AddLocationResolver::BRANCH)->where('place_id', $branchId);
    }
}
