<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Requests\Admin\StorePartnerRequest;
use App\Http\Requests\Admin\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;

class PartnerController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Partner::class;
    }

    protected function resourceClass(): string
    {
        return PartnerResource::class;
    }

    public function store(StorePartnerRequest $request): PartnerResource
    {
        return new PartnerResource(Partner::create($request->validated()));
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): PartnerResource
    {
        $partner->update($request->validated());

        return new PartnerResource($partner);
    }
}
