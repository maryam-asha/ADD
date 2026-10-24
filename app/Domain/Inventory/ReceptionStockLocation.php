<?php

namespace App\Domain\Inventory;

use Add\Inventory\Support\Place;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Finance\Services\CashRegisterService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Adapters\AddLocationResolver;

/**
 * Where reception takes stock from when it sells or records consumption:
 * the place the desk named (a store room, the café counter), or else the
 * branch of the cash register the operator has open — the desk they are
 * standing at.
 */
class ReceptionStockLocation
{
    public function __construct(private readonly CashRegisterService $registers) {}

    /**
     * @param  array{type: string, id: int|string}|null  $requested  already validated
     *
     * @throws ReceptionActionException 422 when neither is known
     */
    public function resolve(?array $requested, User $operator): Place
    {
        if ($requested !== null) {
            return Place::from($requested);
        }

        $branchId = $this->registers->currentSession($operator)?->branch_id;

        if ($branchId === null) {
            throw new ReceptionActionException('api.reception.stock_location_required');
        }

        return new Place(AddLocationResolver::BRANCH, (int) $branchId);
    }
}
