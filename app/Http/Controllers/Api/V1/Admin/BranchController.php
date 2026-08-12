<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use Illuminate\Http\JsonResponse;

class BranchController extends AdminResourceController
{
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
        return new BranchResource(Branch::create(array_merge(
            ['is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return response()->json(['message' => __('api.admin.branch_updated')]);
    }
}
