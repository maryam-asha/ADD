<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\Partner;
use App\Http\Requests\Admin\StorePartnerRequest;
use App\Http\Requests\Admin\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;

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

    /**
     * `order`/`is_active` are set explicitly here, not left to the
     * migration's column defaults — Eloquent doesn't re-fetch DB-side
     * defaults into an unrefreshed model, so omitting either would
     * otherwise come back `null` in this very response even though the DB
     * row is correctly `0`/`true` (the same lesson already documented for
     * `CompanyController::store` and `UserFactory`).
     */
    public function store(StorePartnerRequest $request): PartnerResource
    {
        return new PartnerResource(Partner::create(array_merge(
            ['order' => 0, 'is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): PartnerResource
    {
        $partner->update($request->validated());

        return new PartnerResource($partner);
    }
}
