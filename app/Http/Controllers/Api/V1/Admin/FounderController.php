<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\Founder;
use App\Http\Requests\Admin\StoreFounderRequest;
use App\Http\Requests\Admin\UpdateFounderRequest;
use App\Http\Resources\FounderResource;
use Illuminate\Http\JsonResponse;

class FounderController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Founder::class;
    }

    protected function resourceClass(): string
    {
        return FounderResource::class;
    }

    /**
     * `order` is set explicitly here, not left to the migration's column
     * default — Eloquent doesn't re-fetch DB-side defaults into an
     * unrefreshed model, so an omitted `order` would otherwise come back
     * `null` in this very response even though the DB row is correctly `0`
     * (the same lesson already documented for `CompanyController::store`
     * and `UserFactory`).
     */
    public function store(StoreFounderRequest $request): FounderResource
    {
        return new FounderResource(Founder::create(array_merge(
            ['order' => 0],
            $request->validated()
        )));
    }

    public function update(UpdateFounderRequest $request, Founder $founder): JsonResponse
    {
        $founder->update($request->validated());

        return response()->json(['message' => __('api.admin.founder_updated')]);
    }
}
