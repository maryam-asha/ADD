<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Inventory\Adapters\AddLocationResolver;
use App\Http\Controllers\Api\V1\Admin\Concerns\RefusesDeletingPlacesWithInventory;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class BranchController extends AdminResourceController
{
    use RefusesDeletingPlacesWithInventory;

    protected function modelClass(): string
    {
        return Branch::class;
    }

    protected function resourceClass(): string
    {
        return BranchResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    public function store(StoreBranchRequest $request): BranchResource
    {
        $data = $request->validated();
        $data['is_active'] ??= true;

        return new BranchResource(Branch::create($data));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return response()->json(['message' => __('api.admin.branch_updated')]);
    }

    /**
     * Not while it, or a place deleted with it, still holds inventory.
     */
    public function destroy(int $id): Response
    {
        $this->refuseIfHoldsInventory(AddLocationResolver::BRANCH, $id);

        return parent::destroy($id);
    }
}
