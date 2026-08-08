<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\Founder;
use App\Http\Requests\Admin\StoreFounderRequest;
use App\Http\Requests\Admin\UpdateFounderRequest;
use App\Http\Resources\FounderResource;

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

    public function store(StoreFounderRequest $request): FounderResource
    {
        return new FounderResource(Founder::create($request->validated()));
    }

    public function update(UpdateFounderRequest $request, Founder $founder): FounderResource
    {
        $founder->update($request->validated());

        return new FounderResource($founder);
    }
}
